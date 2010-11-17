<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2010 Céondo Ltd and contributors.
#
# Plume Framework is free software; you can redistribute it and/or modify
# it under the terms of the GNU Lesser General Public License as published by
# the Free Software Foundation; either version 2.1 of the License, or
# (at your option) any later version.
#
# Plume Framework is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
#
# ***** END LICENSE BLOCK ***** */

require_once(IDF_PATH.'/../contrib/zipstream-php-0.2.2/zipstream.php');

/**
 * Special response object to output 
 *
 * The Content-Length will not be set as it is not possible to predict it.
 *
 * Note: The ZipArchive version 0.2.2 has been patched in-tree with this
 *       patch http://pastebin.ca/1977584 to avoid a couple of PHP notices
 *
 */
class IDF_Scm_Monotone_ZipRender extends Pluf_HTTP_Response
{
    /**
     * The revision argument must be a safe string!
     *
     * @param Object stdio context
     * @param string revision
     * @param string Mimetype (null)
     */

    private $stdio = null;
    private $revision = null;

    function __construct($stdio, $revision)
    {
        parent::__construct($revision, 'application/x-zip');
        $this->stdio = $stdio;
        $this->revision = $revision;
    }

    /**
     * Render a response object.
     */
    function render($output_body=true)
    {
        $this->outputHeaders();

        if ($output_body) {
            $manifest = $this->stdio->exec(array('get_manifest_of', $this->revision));
            $stanzas = IDF_Scm_Monotone_BasicIO::parse($manifest);

            $zip = new ZipStream();

            foreach ($stanzas as $stanza) {
                if ($stanza[0]['key'] != 'file')
                    continue;
                $content = $this->stdio->exec(array('get_file', $stanza[1]['hash']));
                $zip->add_file($stanza[0]['values'][0], $content);
            }

            $zip->finish();
        }
    }
}
