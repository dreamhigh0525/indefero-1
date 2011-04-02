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

include 'IDF/Scm/Monotone/BasicIO.php';

class IDF_Scm_Monotone_BasicIOTest extends PHPUnit_Framework_TestCase
{
    public function testParse()
    {
        $stanzas = IDF_Scm_Monotone_BasicIO::parse(null);
        $this->assertTrue(is_array($stanzas) && count($stanzas) == 0);

        // single stanza, single line, only key
        $stanzas = IDF_Scm_Monotone_BasicIO::parse('foo');
        $this->assertEquals(1, count($stanzas));
        $stanza = $stanzas[0];
        $this->assertEquals(1, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('foo', $entry['key']);
        $this->assertTrue(!array_key_exists('hash', $entry));
        $this->assertTrue(!array_key_exists('values', $entry));

        // single stanza, single line, key with hash
        $stanzas = IDF_Scm_Monotone_BasicIO::parse("foo    [0123456789012345678901234567890123456789]");
        $this->assertEquals(1, count($stanzas));
        $stanza = $stanzas[0];
        $this->assertEquals(1, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('foo', $entry['key']);
        $this->assertEquals("0123456789012345678901234567890123456789", $entry['hash']);
        $this->assertTrue(!array_key_exists('values', $entry));

        // single stanza, single line, key with two values
        $stanzas = IDF_Scm_Monotone_BasicIO::parse("foo    \"bar\n\nbaz\" \"bla\"");
        $this->assertEquals(1, count($stanzas));
        $stanza = $stanzas[0];
        $this->assertEquals(1, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('foo', $entry['key']);
        $this->assertTrue(!array_key_exists('hash', $entry));
        $this->assertEquals(array("bar\n\nbaz", "bla"), $entry['values']);

        // single stanza, single line, key with a value and a hash
        $stanzas = IDF_Scm_Monotone_BasicIO::parse("foo    \"bar\n\nbaz\" [0123456789012345678901234567890123456789]");
        $this->assertEquals(1, count($stanzas));
        $stanza = $stanzas[0];
        $this->assertEquals(1, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('foo', $entry['key']);
        $this->assertTrue(!array_key_exists('hash', $entry));
        $this->assertEquals(array("bar\n\nbaz", "0123456789012345678901234567890123456789"), $entry['values']);

        // single stanza, two lines, keys with single value / hash
        $stanzas = IDF_Scm_Monotone_BasicIO::parse("foo    \"bar\"\nbaz [0123456789012345678901234567890123456789]");
        $this->assertEquals(1, count($stanzas));
        $stanza = $stanzas[0];
        $this->assertEquals(2, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('foo', $entry['key']);
        $this->assertTrue(!array_key_exists('hash', $entry));
        $this->assertEquals(array("bar"), $entry['values']);
        $entry = $stanza[1];
        $this->assertEquals('baz', $entry['key']);
        $this->assertTrue(!array_key_exists('values', $entry));
        $this->assertEquals("0123456789012345678901234567890123456789", $entry['hash']);

         // two stanza, one two liner, one one liner
        $stanzas = IDF_Scm_Monotone_BasicIO::parse("foo    \"bar\"\nbaz [0123456789012345678901234567890123456789]\n\nbla \"blub\"");
        $this->assertEquals(2, count($stanzas));
        $stanza = $stanzas[0];
        $this->assertEquals(2, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('foo', $entry['key']);
        $this->assertTrue(!array_key_exists('hash', $entry));
        $this->assertEquals(array("bar"), $entry['values']);
        $entry = $stanza[1];
        $this->assertEquals('baz', $entry['key']);
        $this->assertTrue(!array_key_exists('values', $entry));
        $this->assertEquals("0123456789012345678901234567890123456789", $entry['hash']);
        $stanza = $stanzas[1];
        $this->assertEquals(1, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('bla', $entry['key']);
        $this->assertTrue(!array_key_exists('hash', $entry));
        $this->assertEquals(array("blub"), $entry['values']);

        // (un)escaping tests
        $stanzas = IDF_Scm_Monotone_BasicIO::parse('foo    "bar\\baz" "bla\"blub"');
        $this->assertEquals(1, count($stanzas));
        $stanza = $stanzas[0];
        $this->assertEquals(1, count($stanza));
        $entry = $stanza[0];
        $this->assertEquals('foo', $entry['key']);
        $this->assertTrue(!array_key_exists('hash', $entry));
        $this->assertEquals(array('bar\baz', 'bla"blub'), $entry['values']);

    }

    public function testCompile()
    {
        $stanzas = array(
            array(
                array('key' => 'foo'),
                array('key' => 'bar', 'values' => array('one', "two\nthree")),
            ),
            array(
                array('key' => 'baz', 'hash' => '0123456789012345678901234567890123456789'),
                array('key' => 'blablub', 'values' => array('one"two', 'three\four')),
            ),
        );

        $ex =<<<END
foo
bar "one" "two
three"

    baz [0123456789012345678901234567890123456789]
blablub "one\"two" "three\\\\four"

END;
        $this->assertEquals($ex, IDF_Scm_Monotone_BasicIO::compile($stanzas));

        // keys must not be null
        $stanzas = array(
            array(
                array('key' => null, 'values' => array('foo')),
            ),
        );

        $thrown = false;
        try {
            IDF_Scm_Monotone_BasicIO::compile($stanzas);
        } catch (IDF_Scm_Exception $e) {
            $this->assertRegExp('/^"key" not found in basicio stanza/', $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        // ...nor completly non-existing
        $stanzas = array(
            array(
                array('values' => array('foo')),
            ),
        );

        $thrown = false;
        try {
            IDF_Scm_Monotone_BasicIO::compile($stanzas);
        } catch (IDF_Scm_Exception $e) {
            $this->assertRegExp('/^"key" not found in basicio stanza/', $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }
}

