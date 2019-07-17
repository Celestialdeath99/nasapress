<?php

namespace Illuminate\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessUtils;
use Symfony\Component\Process\PhpExecutableFinder;

class Composer
{
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * The working path to regenerate from.
     *
     * @var string
     */
    protected $workingPath;

    /**
     * Create a new Composer manager instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @param  string|null  $workingPath
     * @return void
     */
    public function __construct(Filesystem $files, $workingPath = null)
    {
        $this->files = $files;
        $this->workingPath = $workingPath;
    }

    /**
     * Regenerate the Composer autoloader files.
     *
     * @param  string  $extra
     * @return void
     */
    public function dumpAutoloads($extra = '')
    {
        $process = $this->getProcess();

        $process->setCommandLine(trim($this->findComposer().' dump-autoload '.$extra));

        $process->run();
    }

    /**
     * Regenerate the optimized Composer autoloader files.
     *
     * @return void
     */
    public function dumpOptimized()
    {
        $this->dumpAutoloads('--optimize');
    }

    /**
     * Set the working path used by the class.
     *
     * @param  string  $path
     * @return $this
     */
    public function setWorkingPath($path)
    {
        $this->workingPath = realpath($path);

        return $this;
    }
}
