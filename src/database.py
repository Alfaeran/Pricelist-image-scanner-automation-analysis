"""
database.py — SQLite Storage Layer for Pricelist Scanner Automation Dashboard.

Provides thread-safe, long-term memory storage for package scan history.
Uses a check_same_thread=False connection for Streamlit compatibility,
combined with a threading.Lock for safe concurrent writes.

Schema (package_history):
    id          INTEGER PRIMARY KEY AUTOINCREMENT
    scan_date   TEXT    (ISO-8601 datetime string)
    location    TEXT
    provider    TEXT
    gb          REAL
    days        INTEGER
    price       INTEGER
    yield_val   INTEGER
    category    TEXT
"""

from __future__ import annotations

import sqlite3
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Generator

import pandas as pd

# ---------------------------------------------------------------------------
# Default database path — sits next to this module.
# Override by passing a custom `db_path` to `Database(...)`.
# ---------------------------------------------------------------------------
_DEFAULT_DB_PATH = Path(__file__).parent.parent / "data" / "package_history.db"

# ---------------------------------------------------------------------------
# SQL Constants
# ---------------------------------------------------------------------------
_CREATE_TABLE_SQL = """
CREATE TABLE IF NOT EXISTS package_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_date   TEXT    NOT NULL,
    location    TEXT    NOT NULL,
    provider    TEXT    NOT NULL,
    gb          REAL    NOT NULL,
    days        INTEGER NOT NULL,
    price       INTEGER NOT NULL,
    yield_val   INTEGER NOT NULL,
    category    TEXT    NOT NULL
);
"""

_CREATE_INDEXES_SQL = [
    "CREATE INDEX IF NOT EXISTS idx_ph_scan_date ON package_history (scan_date);",
    "CREATE INDEX IF NOT EXISTS idx_ph_location  ON package_history (location);",
    "CREATE INDEX IF NOT EXISTS idx_ph_provider  ON package_history (provider);",
    "CREATE INDEX IF NOT EXISTS idx_ph_category  ON package_history (category);",
]

_INSERT_SQL = """
INSERT INTO package_history
    (scan_date, location, provider, gb, days, price, yield_val, category)
VALUES
    (:scan_date, :location, :provider, :gb, :days, :price, :yield_val, :category);
"""

_SELECT_ALL_SQL = "SELECT * FROM package_history ORDER BY scan_date DESC;"


# ---------------------------------------------------------------------------
# Database Class
# ---------------------------------------------------------------------------
class Database:
    """Thread-safe SQLite wrapper for the package_history table.

    Usage:
        db = Database()                     # uses default path
        db = Database("path/to/custom.db")  # custom path

        db.insert_packages(df, location="Jakarta")
        all_rows = db.get_all_packages()
    """

    def __init__(self, db_path: str | Path = _DEFAULT_DB_PATH) -> None:
        self._db_path = Path(db_path)
        self._db_path.parent.mkdir(parents=True, exist_ok=True)

        # Threading lock guards all write operations so concurrent
        # Streamlit sessions don't collide.
        self._lock = threading.Lock()

        # A single long-lived connection with check_same_thread=False
        # allows Streamlit's re-run model (multiple threads) to share
        # the connection safely — guarded by our own lock.
        self._conn = sqlite3.connect(
            str(self._db_path),
            check_same_thread=False,
        )
        self._conn.execute("PRAGMA journal_mode=WAL;")  # better concurrency
        self._conn.execute("PRAGMA foreign_keys=ON;")
        self._conn.row_factory = sqlite3.Row

        self._bootstrap()

    # ----- internal helpers ------------------------------------------------

    def _bootstrap(self) -> None:
        """Create table & indexes if they don't exist yet."""
        with self._atomic() as cur:
            cur.execute(_CREATE_TABLE_SQL)
            for idx_sql in _CREATE_INDEXES_SQL:
                cur.execute(idx_sql)

    @contextmanager
    def _atomic(self) -> Generator[sqlite3.Cursor, None, None]:
        """Context manager that acquires the lock, yields a cursor,
        and commits on success / rolls back on error."""
        self._lock.acquire()
        cur = self._conn.cursor()
        try:
            yield cur
            self._conn.commit()
        except Exception:
            self._conn.rollback()
            raise
        finally:
            cur.close()
            self._lock.release()

    # ----- public CRUD -----------------------------------------------------

    def insert_packages(
        self,
        dataframe: pd.DataFrame,
        location: str,
    ) -> int:
        """Bulk-insert rows from a DataFrame into package_history.

        Parameters
        ----------
        dataframe : pd.DataFrame
            Must contain columns: scan_date, provider, gb, days,
            price, yield_val, category.
            If ``location`` column is present it will be overridden by the
            *location* argument.
        location : str
            The scan location label (e.g. "Jakarta", "Surabaya").

        Returns
        -------
        int
            Number of rows inserted.
        """
        required_cols = {"scan_date", "provider", "gb", "days", "price", "yield_val", "category"}
        missing = required_cols - set(dataframe.columns)
        if missing:
            raise ValueError(
                f"DataFrame is missing required columns: {missing}"
            )

        # Build list of dicts for executemany — inject location.
        records = dataframe.to_dict(orient="records")
        for rec in records:
            rec["location"] = location

        with self._atomic() as cur:
            cur.executemany(_INSERT_SQL, records)

        return len(records)

    def get_all_packages(self) -> pd.DataFrame:
        """Return every row in package_history as a DataFrame.

        Returns
        -------
        pd.DataFrame
            Columns mirror the table schema, ordered by scan_date DESC.
        """
        df = pd.read_sql_query(_SELECT_ALL_SQL, self._conn)
        return df

    # ----- convenience query helpers ---------------------------------------

    def query(self, sql: str, params: tuple | dict | None = None) -> pd.DataFrame:
        """Run an arbitrary SELECT and return the result as a DataFrame.

        Parameters
        ----------
        sql : str
            A SQL SELECT statement.
        params : tuple | dict | None
            Bind parameters.

        Returns
        -------
        pd.DataFrame
        """
        return pd.read_sql_query(sql, self._conn, params=params or ())

    def get_packages_by_location(self, location: str) -> pd.DataFrame:
        """Filter package_history by location."""
        sql = "SELECT * FROM package_history WHERE location = :loc ORDER BY scan_date DESC;"
        return pd.read_sql_query(sql, self._conn, params={"loc": location})

    def get_packages_by_provider(self, provider: str) -> pd.DataFrame:
        """Filter package_history by provider."""
        sql = "SELECT * FROM package_history WHERE provider = :prov ORDER BY scan_date DESC;"
        return pd.read_sql_query(sql, self._conn, params={"prov": provider})

    def get_latest_scan_date(self) -> str | None:
        """Return the most recent scan_date, or None if table is empty."""
        cur = self._conn.execute(
            "SELECT MAX(scan_date) AS latest FROM package_history;"
        )
        row = cur.fetchone()
        return row["latest"] if row else None

    def count(self) -> int:
        """Return total row count."""
        cur = self._conn.execute("SELECT COUNT(*) AS cnt FROM package_history;")
        return cur.fetchone()["cnt"]

    def delete_by_scan_date(self, scan_date: str) -> int:
        """Delete all rows matching a specific scan_date.

        Returns the number of deleted rows.
        """
        with self._atomic() as cur:
            cur.execute(
                "DELETE FROM package_history WHERE scan_date = :sd;",
                {"sd": scan_date},
            )
            return cur.rowcount

    # ----- lifecycle -------------------------------------------------------

    def close(self) -> None:
        """Close the underlying SQLite connection."""
        self._conn.close()

    def __del__(self) -> None:
        try:
            self.close()
        except Exception:
            pass


# ---------------------------------------------------------------------------
# Module-level singleton (convenient for Streamlit @st.cache_resource)
# ---------------------------------------------------------------------------
_singleton_lock = threading.Lock()
_singleton: Database | None = None


def get_database(db_path: str | Path = _DEFAULT_DB_PATH) -> Database:
    """Return (or create) a module-level singleton Database instance.

    Ideal for use with Streamlit's @st.cache_resource:

        @st.cache_resource
        def get_db():
            return get_database()
    """
    global _singleton
    with _singleton_lock:
        if _singleton is None:
            _singleton = Database(db_path)
    return _singleton
