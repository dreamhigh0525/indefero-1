<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2008-2011 Céondo Ltd and contributors.
#
# InDefero is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# InDefero is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
#
# ***** END LICENSE BLOCK ***** */

/**
 * Diff parser.
 *
 */
class IDF_Diff
{
    public $path_strip_level = 0;
    protected $lines = array();

    public $files = array();

    public function __construct($diff, $path_strip_level = 0)
    {
        $this->path_strip_level = $path_strip_level;
        // this works because in unified diff format even empty lines are
        // either prefixed with a '+', '-' or ' '
        $this->lines = preg_split("/\015\012|\015|\012/", $diff, -1, PREG_SPLIT_NO_EMPTY);
    }

    public function parse()
    {
        $current_file = '';
        $current_chunk = 0;
        $lline = 0;
        $rline = 0;
        $files = array();
        $indiff = false; // Used to skip the headers in the git patches
        $i = 0; // Used to skip the end of a git patch with --\nversion number
        $diffsize = count($this->lines);
        while ($i < $diffsize) {
            // look for the potential beginning of a diff
            if (substr($this->lines[$i], 0, 4) !== '--- ') {
                $i++;
                continue;
            }

            // we're inside a diff candiate
            $oldfileline = $this->lines[$i++];
            $newfileline = $this->lines[$i++];
            if (substr($newfileline, 0, 4) !== '+++ ') {
                // not a valid diff here, move on
                continue;
            }

            // use new file name by default
            preg_match("/^\+\+\+ ([^\t]+)/", $newfileline, $m);
            $current_file = $m[1];
            if ($current_file === '/dev/null') {
                // except if it's /dev/null, use the old one instead
                // eg. mtn 0.48 and newer
                preg_match("/^--- ([^\t]+)/", $oldfileline, $m);
                $current_file = $m[1];
            }
            if ($this->path_strip_level > 0) {
                $fileparts = explode('/', $current_file, $this->path_strip_level+1);
                $current_file = array_pop($fileparts);
            }
            $current_chunk = 0;
            $files[$current_file] = array();
            $files[$current_file]['chunks'] = array();
            $files[$current_file]['chunks_def'] = array();

            while ($i < $diffsize && substr($this->lines[$i], 0, 3) === '@@ ') {
                $elems = preg_match('/@@ -(\d+),?(\d*) \+(\d+),?(\d*) @@.*/',
                                    $this->lines[$i++], $results);
                if ($elems != 1) {
                    // hunk is badly formatted
                    break;
                }
                $delstart = $results[1];
                $dellines = $results[2] === '' ? 1 : $results[2];
                $addstart = $results[3];
                $addlines = $results[4] === '' ? 1 : $results[4];

                $files[$current_file]['chunks_def'][] = array(
                    array($delstart, $dellines), array($addstart, $addlines)
                );
                $files[$current_file]['chunks'][] = array();

                while ($i < $diffsize && ($addlines >= 0 || $dellines >= 0)) {
                    $linetype = $this->lines[$i] != '' ? $this->lines[$i][0] : false;
                    switch ($linetype) {
                        case ' ':
                            $files[$current_file]['chunks'][$current_chunk][] =
                                array($delstart, $addstart, substr($this->lines[$i++], 1));
                            $dellines--;
                            $addlines--;
                            $delstart++;
                            $addstart++;
                            break;
                        case '+':
                            $files[$current_file]['chunks'][$current_chunk][] =
                                array('', $addstart, substr($this->lines[$i++], 1));
                            $addlines--;
                            $addstart++;
                            break;
                        case '-':
                            $files[$current_file]['chunks'][$current_chunk][] =
                                array($delstart, '', substr($this->lines[$i++], 1));
                            $dellines--;
                            $delstart++;
                            break;
                        case '\\':
                            // ignore newline handling for now, see issue 636
                            $i++;
                            continue;
                        default:
                            break 2;
                    }
                }
                $current_chunk++;
            }
        }
        $this->files = $files;
        return $files;
    }

    /**
     * Return the html version of a parsed diff.
     */
    public function as_html()
    {
        $out = '';
        foreach ($this->files as $filename=>$file) {
            $pretty = '';
            $fileinfo = IDF_FileUtil::getMimeType($filename);
            if (IDF_FileUtil::isSupportedExtension($fileinfo[2])) {
                $pretty = ' prettyprint';
            }
            $out .= "\n".'<table class="diff" summary="">'."\n";
            $out .= '<tr id="diff-'.md5($filename).'"><th colspan="3">'.Pluf_esc($filename).'</th></tr>'."\n";
            $cc = 1;
            foreach ($file['chunks'] as $chunk) {
                foreach ($chunk as $line) {
                    if ($line[0] and $line[1]) {
                        $class = 'diff-c';
                    } elseif ($line[0]) {
                        $class = 'diff-r';
                    } else {
                        $class = 'diff-a';
                    }
                    $line_content = self::padLine(Pluf_esc($line[2]));
                    $out .= sprintf('<tr class="diff-line"><td class="diff-lc">%s</td><td class="diff-lc">%s</td><td class="%s%s mono">%s</td></tr>'."\n", $line[0], $line[1], $class, $pretty, $line_content);
                }
                if (count($file['chunks']) > $cc)
                $out .= '<tr class="diff-next"><td>...</td><td>...</td><td>&nbsp;</td></tr>'."\n";
                $cc++;
            }
            $out .= '</table>';
        }
        return Pluf_Template::markSafe($out);
    }

    public static function padLine($line)
    {
        $line = str_replace("\t", '    ', $line);
        $n = strlen($line);
        for ($i=0;$i<$n;$i++) {
            if (substr($line, $i, 1) != ' ') {
                break;
            }
        }
        return str_repeat('&nbsp;', $i).substr($line, $i);
    }

    /**
     * Review patch.
     *
     * Given the original file as a string and the parsed
     * corresponding diff chunks, generate a side by side view of the
     * original file and new file with added/removed lines.
     *
     * Example of use:
     *
     * $diff = new IDF_Diff(file_get_contents($diff_file));
     * $orig = file_get_contents($orig_file);
     * $diff->parse();
     * echo $diff->fileCompare($orig, $diff->files[$orig_file], $diff_file);
     *
     * @param string Original file
     * @param array Chunk description of the diff corresponding to the file
     * @param string Original file name
     * @param int Number of lines before/after the chunk to be displayed (10)
     * @return Pluf_Template_SafeString The table body
     */
    public function fileCompare($orig, $chunks, $filename, $context=10)
    {
        $orig_lines = preg_split("/\015\012|\015|\012/", $orig);
        $new_chunks = $this->mergeChunks($orig_lines, $chunks, $context);
        return $this->renderCompared($new_chunks, $filename);
    }

    public function mergeChunks($orig_lines, $chunks, $context=10)
    {
        $spans = array();
        $new_chunks = array();
        $min_line = 0;
        $max_line = 0;
        //if (count($chunks['chunks_def']) == 0) return '';
        foreach ($chunks['chunks_def'] as $chunk) {
            $start = ($chunk[0][0] > $context) ? $chunk[0][0]-$context : 0;
            $end = (($chunk[0][0]+$chunk[0][1]+$context-1) < count($orig_lines)) ? $chunk[0][0]+$chunk[0][1]+$context-1 : count($orig_lines);
            $spans[] = array($start, $end);
        }
        // merge chunks/get the chunk lines
        // these are reference lines
        $chunk_lines = array();
        foreach ($chunks['chunks'] as $chunk) {
            foreach ($chunk as $line) {
                $chunk_lines[] = $line;
            }
        }
        $i = 0;
        foreach ($chunks['chunks'] as $chunk) {
            $n_chunk = array();
            // add lines before
            if ($chunk[0][0] > $spans[$i][0]) {
                for ($lc=$spans[$i][0];$lc<$chunk[0][0];$lc++) {
                    $exists = false;
                    foreach ($chunk_lines as $line) {
                        if ($lc == $line[0]
                            or ($chunk[0][1]-$chunk[0][0]+$lc) == $line[1]) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $orig = isset($orig_lines[$lc-1]) ? $orig_lines[$lc-1] : '';
                        $n_chunk[] = array(
                                           $lc,
                                           $chunk[0][1]-$chunk[0][0]+$lc,
                                           $orig
                                           );
                    }
                }
            }
            // add chunk lines
            foreach ($chunk as $line) {
                $n_chunk[] = $line;
            }
            // add lines after
            $lline = $line;
            if (!empty($lline[0]) and $lline[0] < $spans[$i][1]) {
                for ($lc=$lline[0];$lc<=$spans[$i][1];$lc++) {
                    $exists = false;
                    foreach ($chunk_lines as $line) {
                        if ($lc == $line[0] or ($lline[1]-$lline[0]+$lc) == $line[1]) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $n_chunk[] = array(
                                           $lc,
                                           $lline[1]-$lline[0]+$lc,
                                           $orig_lines[$lc-1]
                                           );
                    }
                }
            }
            $new_chunks[] = $n_chunk;
            $i++;
        }
        // Now, each chunk has the right length, we need to merge them
        // when needed
        $nnew_chunks = array();
        $i = 0;
        foreach ($new_chunks as $chunk) {
            if ($i>0) {
                $lline = end($nnew_chunks[$i-1]);
                if ($chunk[0][0] <= $lline[0]+1) {
                    // need merging
                    foreach ($chunk as $line) {
                        if ($line[0] > $lline[0] or empty($line[0])) {
                            $nnew_chunks[$i-1][] = $line;
                        }
                    }
                } else {
                    $nnew_chunks[] = $chunk;
                    $i++;
                }
            } else {
                $nnew_chunks[] = $chunk;
                $i++;
            }
        }
        return $nnew_chunks;
    }

    public function renderCompared($chunks, $filename)
    {
        $fileinfo = IDF_FileUtil::getMimeType($filename);
        $pretty = '';
        if (IDF_FileUtil::isSupportedExtension($fileinfo[2])) {
            $pretty = ' prettyprint';
        }
        $out = '';
        $cc = 1;
        $i = 0;
        foreach ($chunks as $chunk) {
            foreach ($chunk as $line) {
                $line1 = '&nbsp;';
                $line2 = '&nbsp;';
                $line[2] = (strlen($line[2])) ? self::padLine(Pluf_esc($line[2])) : '&nbsp;';
                if ($line[0] and $line[1]) {
                    $class = 'diff-c';
                    $line1 = $line2 = $line[2];
                } elseif ($line[0]) {
                    $class = 'diff-r';
                    $line1 = $line[2];
                } else {
                    $class = 'diff-a';
                    $line2 = $line[2];
                }
                $out .= sprintf('<tr class="diff-line"><td class="diff-lc">%s</td><td class="%s mono%s"><code>%s</code></td><td class="diff-lc">%s</td><td class="%s mono%s"><code>%s</code></td></tr>'."\n", $line[0], $class, $pretty, $line1, $line[1], $class, $pretty, $line2);
            }
            if (count($chunks) > $cc)
                $out .= '<tr class="diff-next"><td>...</td><td>&nbsp;</td><td>...</td><td>&nbsp;</td></tr>'."\n";
            $cc++;
            $i++;
        }
        return Pluf_Template::markSafe($out);
    }
}
