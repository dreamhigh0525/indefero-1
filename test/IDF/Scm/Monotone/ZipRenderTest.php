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

require_once 'IDF/Scm/Monotone/ZipRender.php';
require_once 'IDF/Scm/Monotone/IStdio.php';

class ZipRenderStdioMock implements IDF_Scm_Monotone_IStdio
{
    // unused
    public function __construct(IDF_Project $project) {}

    // unused
    public function start() {}

    // unused
    public function stop() {}

    public function exec(array $args, array $options = array())
    {
        if ($args[0] == 'certs') {
            $basicio =<<<END
      key [0504aea5d3716d31281171aaecf3a7c227e5545b]
signature "ok"
     name "author"
    value "joe@home"
    trust "trusted"

      key [0504aea5d3716d31281171aaecf3a7c227e5545b]
signature "ok"
     name "branch"
    value "foo"
    trust "trusted"

      key [0504aea5d3716d31281171aaecf3a7c227e5545b]
signature "ok"
     name "changelog"
    value "test"
    trust "trusted"

      key [0504aea5d3716d31281171aaecf3a7c227e5545b]
signature "ok"
     name "date"
    value "2009-07-06T22:06:27"
    trust "trusted"

END;
            return $basicio;
        }
        if ($args[0] == 'get_manifest_of') {
            $basicio =<<<END
format_version "1"

dir ""

   file "foo"
content [6fcf9dfbd479ed82697fee719b9f8c610a11ff2a]

dir "bar"

   file "bar/baz"
content [9063a9f0e032b6239403b719cbbba56ac4e4e45f]

END;
            return $basicio;
        }

        if ($args[0] == 'get_file') {
            if ($args[1] == '6fcf9dfbd479ed82697fee719b9f8c610a11ff2a') {
                return 'This is foo.';
            }
            if ($args[1] == '9063a9f0e032b6239403b719cbbba56ac4e4e45f') {
                return 'This is baz.';
            }
            throw new Exception('unexpected id ' . $args[1]);
        }

        throw new Exception('unexpected command ' . $args[0]);
    }

    // unused
    public function getLastOutOfBandOutput() {}
}

class IDF_Scm_Monotone_ZipRenderTest extends PHPUnit_Framework_TestCase
{
    // we can not test header sending with PHP-CLI, as header() is ignored
    // in this environment
    public function testRender()
    {
        $mock = new ZipRenderStdioMock(new IDF_Project());
        $renderer = new IDF_Scm_Monotone_ZipRender($mock, '97fee719b9f8c610a11ff2a9063a9f0e032b6');

        ob_start();
        $renderer->render(true);
        $zipcontents = ob_get_contents();
        ob_end_clean();

        // for this version php needs to be compiled with --enable-zip
        if (function_exists('zip_open')) {
            // yes, I'd rather have used php://memory here, but ZipArchive::open()
            // complained that it could not open the stream in question
            $filename = tempnam(Pluf::f('tmp_folder', '/tmp'), __CLASS__.'.');
            file_put_contents($filename, $zipcontents);

            $za = new ZipArchive();
            $za->open($filename);
            $this->assertEquals(2, $za->numFiles);

            // 2009-07-06T22:06:27 - one second
            // (don't ask me why, seems to be some quirk in zipstream)
            $mtime = 1246910787 - 1;

            // foo
            $data = $za->statIndex(0);
            $this->assertEquals('foo', $data['name']);
            $this->assertEquals(12, $data['size']);
            $this->assertEquals($mtime, $data['mtime']);

            // bar/baz
            $data = $za->statIndex(1);
            $this->assertEquals('bar/baz', $data['name']);
            $this->assertEquals(12, $data['size']);
            $this->assertEquals($mtime, $data['mtime']);

            $za->close();
            unlink($filename);
        }
        else {
            $wrapped_act = wordwrap(
                base64_encode($zipcontents),
                32, "\n", true
            );
            $wrapped_exp = wordwrap(
                base64_encode(file_get_contents(DATADIR . '/' . __CLASS__ . '/data.zip')),
                32, "\n", true
            );

            $this->assertEquals($wrapped_exp, $wrapped_act);
        }
    }
}
