<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2010 Céondo Ltd and contributors.
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
 * Utility class to parse and compile basic_io stanzas
 *
 * @author Thomas Keller <me@thomaskeller.biz>
 */
class IDF_Scm_Monotone_BasicIO
{
    /**
     * Parses monotone's basic_io format
     *
     * @param string $in
     * @return array of arrays
     */
    public static function parse($in)
    {
        $pos = 0;
        $stanzas = array();

        while ($pos < strlen($in)) {
            $stanza = array();
            while ($pos < strlen($in)) {
                if ($in[$pos] == "\n") break;

                $stanzaLine = array('key' => '', 'values' => array(), 'hash' => null);
                while ($pos < strlen($in)) {
                    $ch = $in[$pos];
                    if ($ch == '"' || $ch == '[') break;
                    ++$pos;
                    if ($ch == ' ') continue;
                    $stanzaLine['key'] .= $ch;
                }

                if ($in[$pos] == '[') {
                    unset($stanzaLine['values']);
                    ++$pos; // opening square bracket
                    $stanzaLine['hash'] = substr($in, $pos, 40);
                    $pos += 40;
                    ++$pos; // closing square bracket
                }
                else
                {
                    unset($stanzaLine['hash']);
                    $valCount = 0;
                    while ($in[$pos] == '"') {
                        ++$pos; // opening quote
                        $stanzaLine['values'][$valCount] = '';
                        while ($pos < strlen($in)) {
                            $ch = $in[$pos]; $pr = $in[$pos-1];
                            if ($ch == '"' && $pr != '\\') break;
                            ++$pos;
                            $stanzaLine['values'][$valCount] .= $ch;
                        }
                        ++$pos; // closing quote

                        if ($in[$pos] == ' ') {
                            ++$pos; // space
                            ++$valCount;
                        }
                    }

                    for ($i = 0; $i <= $valCount; $i++) {
                        $stanzaLine['values'][$i] = str_replace(
                            array("\\\\", "\\\""),
                            array("\\", "\""),
                            $stanzaLine['values'][$i]
                        );
                    }
                }

                $stanza[] = $stanzaLine;
                ++$pos; // newline
            }
            $stanzas[] = $stanza;
            ++$pos; // newline
        }
        return $stanzas;
    }

    /**
     * Compiles monotone's basicio format
     *
     * @param array $in Array of arrays
     * @return string
     */
    public static function compile($in)
    {
        $out = "";
        $first = true;
        foreach ((array)$in as $sx => $stanza) {
            if ($first)
                $first = false;
            else
                $out .= "\n";

            $maxkeylength = 0;
            foreach ((array)$stanza as $lx => $line) {
                if (!array_key_exists('key', $line)) {
                    throw new IDF_Scm_Exception(
                        '"key" not found in basicio stanza '.$sx.', line '.$lx
                    );
                }
                $maxkeylength = max($maxkeylength, strlen($line['key']));
            }

            foreach ((array)$stanza as $lx => $line) {
                $out .= str_pad($line['key'], $maxkeylength, ' ', STR_PAD_LEFT);

                if (array_key_exists('hash', $line)) {
                    $out .= ' ['.$line['hash'].']';
                } else
                if (array_key_exists('values', $line)) {
                    if (!is_array($line['values']) || count($line['values']) == 0) {
                        throw new IDF_Scm_Exception(
                            '"values" must be an array of a size >= 1 '.
                            'in basicio stanza '.$sx.', line '.$lx
                        );
                    }
                    foreach ($line['values'] as $value) {
                        $out .= ' "'.str_replace(
                             array("\\", "\""),
                             array("\\\\", "\\\""),
                             $value).'"';
                    }
                }
                else
                {
                    throw new IDF_Scm_Exception(
                        'neither "hash" nor "values" found in basicio '.
                        'stanza '.$sx.', line '.$lx
                    );
                }

                $out .= "\n";
            }
        }
        return $out;
    }
}

