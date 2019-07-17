<?php

namespace Roots\Sage\Template;

class FileViewFinder extends \Illuminate\View\FileViewFinder
{
    const FALLBACK_PARTS_DELIMITER = '-';

    /**
     * Get an array of possible view files from a single file name.
     *
     * @param  string  $name
     * @return array
     */
    public function getPossibleViewFiles($name)
    {
        $parts = explode(self::FALLBACK_PARTS_DELIMITER, $name);
        $templates[] = array_shift($parts);
        foreach ($parts as $i => $part) {
            $templates[] = $templates[$i].self::FALLBACK_PARTS_DELIMITER.$part;
        }
        rsort($templates);
        return $this->getPossibleViewFilesFromTemplates($templates);
    }
}