<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Copy the Python pipeline into the Laravel app so it ships with the installer.
 *
 * NativePHP only bundles the Laravel directory. The pipeline lives beside it in
 * the repo (../src), which means a packaged app would start with no AI engine
 * at all. This runs during prebuild so the sources travel with the app.
 */
class BundlePython extends Command
{
    protected $signature = 'app:bundle-python {--source= : Where to copy the Python sources from}';

    protected $description = 'Copy the Python AI pipeline into resources/python for packaging';

    public function handle(): int
    {
        $source = rtrim($this->option('source') ?: base_path('../src'), '/\\');
        $target = base_path('resources/python');

        if (!is_file($source . DIRECTORY_SEPARATOR . 'fastapi_app.py')) {
            $this->error("No fastapi_app.py in {$source} - nothing to bundle.");

            return self::FAILURE;
        }

        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            $this->error("Could not create {$target}.");

            return self::FAILURE;
        }

        $files = Finder::create()
            ->files()
            ->in($source)
            ->name('*.py')
            ->exclude('__pycache__');

        $copied = 0;
        foreach ($files as $file) {
            $destination = $target . DIRECTORY_SEPARATOR . $file->getRelativePathname();

            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }

            if (!copy($file->getRealPath(), $destination)) {
                $this->error("Failed to copy {$file->getRelativePathname()}.");

                return self::FAILURE;
            }

            $copied++;
        }

        // Ship the dependency list too, so a user can repair a broken Python
        // environment without going back to the repository.
        $requirements = base_path('../requirements.txt');
        if (is_file($requirements)) {
            copy($requirements, $target . DIRECTORY_SEPARATOR . 'requirements.txt');
            $copied++;
        }

        $this->info("Bundled {$copied} file(s) into resources/python.");

        return self::SUCCESS;
    }
}
