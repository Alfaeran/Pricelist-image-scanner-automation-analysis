<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Make the bundled venv independent of the machine that built it.
 *
 * `python -m venv` writes an interpreter *shim*: venv/Scripts/python.exe is a
 * ~250KB launcher, and pyvenv.cfg points `home` at the Python that created it.
 * On the build machine that path resolves, so everything works and the problem
 * stays invisible. On a user's PC it does not exist, the shim finds no runtime,
 * and the app silently falls back to a system `python` - which is why the
 * installer had to demand Python 3.13 be installed first.
 *
 * The fix is to drop python.org's embeddable distribution (a full interpreter
 * plus its DLLs, no installer, no registry) into the venv and repoint
 * pyvenv.cfg at it. The already-bundled site-packages are portable and stay
 * exactly as they are.
 */
class EmbedPythonRuntime extends Command
{
    protected $signature = 'app:embed-python
        {--py=3.13.0 : Python version to embed}
        {--force : Re-embed even if a runtime is already present}';

    protected $description = 'Embed a self-contained Python runtime into the bundled venv';

    public function handle(): int
    {
        $venv = base_path('venv');

        if (!is_dir($venv)) {
            $this->error("No venv at {$venv} - create it before packaging.");

            return self::FAILURE;
        }

        $version = $this->option('py');
        $runtime = $venv . DIRECTORY_SEPARATOR . 'runtime';
        $marker = $runtime . DIRECTORY_SEPARATOR . 'python.exe';

        if (is_file($marker) && !$this->option('force')) {
            $this->info('Embedded runtime already present - skipping download.');

            return $this->repointConfig($venv, $version);
        }

        $url = "https://www.python.org/ftp/python/{$version}/python-{$version}-embed-amd64.zip";
        $zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "python-embed-{$version}.zip";

        if (!is_file($zip)) {
            $this->info("Downloading {$url}");

            // curl rather than file_get_contents: allow_url_fopen is off in
            // plenty of PHP builds, and this has to work on any build machine.
            $process = new \Symfony\Component\Process\Process(
                ['curl', '-sSL', '--fail', '-o', $zip, $url]
            );
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful() || !is_file($zip) || filesize($zip) < 1_000_000) {
                @unlink($zip);
                $this->error('Download failed: ' . trim($process->getErrorOutput()));

                return self::FAILURE;
            }
        }

        if (!is_dir($runtime) && !mkdir($runtime, 0755, true) && !is_dir($runtime)) {
            $this->error("Could not create {$runtime}.");

            return self::FAILURE;
        }

        // Not ZipArchive: the ext-zip extension is absent from plenty of PHP
        // CLI builds, and this must not depend on how the build machine's PHP
        // was compiled. Expand-Archive ships with Windows.
        $unzip = new \Symfony\Component\Process\Process([
            'powershell', '-NoProfile', '-NonInteractive', '-Command',
            'Expand-Archive -LiteralPath ' . escapeshellarg($zip)
                . ' -DestinationPath ' . escapeshellarg($runtime) . ' -Force',
        ]);
        $unzip->setTimeout(600);
        $unzip->run();

        if (!$unzip->isSuccessful()) {
            $this->error('Could not extract the runtime: ' . trim($unzip->getErrorOutput()));

            return self::FAILURE;
        }

        if (!is_file($marker)) {
            $this->error('Archive extracted but python.exe is missing.');

            return self::FAILURE;
        }

        // The embeddable build ships with site-packages disabled via a ._pth
        // file. Without import site, the venv's dependencies are unreachable,
        // so enable it and add the venv's package directory to the path.
        foreach (glob($runtime . DIRECTORY_SEPARATOR . 'python*._pth') ?: [] as $pth) {
            $lines = file($pth, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_values(array_filter(
                $lines,
                fn ($l) => !str_starts_with(trim($l), '#import site') && trim($l) !== 'import site'
            ));
            $lines[] = '..\Lib\site-packages';
            $lines[] = 'import site';
            file_put_contents($pth, implode("\n", $lines) . "\n");
            $this->info('Enabled site-packages in ' . basename($pth));
        }

        $this->info('Embedded Python runtime at venv/runtime.');

        return $this->repointConfig($venv, $version);
    }

    /**
     * Point pyvenv.cfg at the embedded runtime instead of the build machine's.
     */
    protected function repointConfig(string $venv, string $version): int
    {
        $cfg = $venv . DIRECTORY_SEPARATOR . 'pyvenv.cfg';

        $contents = implode("\n", [
            'home = ' . $venv . DIRECTORY_SEPARATOR . 'runtime',
            'include-system-site-packages = false',
            'version = ' . $version,
            'executable = ' . $venv . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'python.exe',
        ]) . "\n";

        if (file_put_contents($cfg, $contents) === false) {
            $this->error("Could not rewrite {$cfg}.");

            return self::FAILURE;
        }

        $this->info('Repointed pyvenv.cfg at the embedded runtime.');

        return self::SUCCESS;
    }
}
