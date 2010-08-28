<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2008 Céondo Ltd and contributors.
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
 * This classes is a plugin which allows to synchronise access rights
 * between indefero and monotone usher setups.
 */
class IDF_Plugin_SyncMonotone
{
    /**
     * Entry point of the plugin.
     */
    static public function entry($signal, &$params)
    {
        $plug = new IDF_Plugin_SyncMonotone();
        switch ($signal) {
        case 'IDF_Project::created':
            $plug->processMonotoneCreate($params['project']);
            break;
        }
    }

    /**
     * Run mtn init command to create the corresponding monotone
     * repository and add the database to the configured usher instance
     *
     * @param IDF_Project
     */
    function processMonotoneCreate($project)
    {
        if ($project->getConf()->getVal('scm') != 'mtn') {
            return;
        }

        $repotempl = Pluf::f('mtn_repositories', false);
        if ($repotempl === false) {
            throw new IDF_Scm_Exception(
                 '"mtn_repositories" must be defined in your configuration file.'
            );
        }

        $usher_config = Pluf::f('mtn_usher', array());
        if (!array_key_exists('rcfile', $usher_config) ||
            !is_writable($usher_config['rcfile'])) {
            throw new IDF_Scm_Exception(
                 '"rcfile" in "mtn_usher" does not exist or is not writable.'
            );
        }

        $shortname = $project->shortname;
        $dbfile = sprintf($repotempl, $shortname);
        if (file_exists($dbfile)) {
            throw new IDF_Scm_Exception(sprintf(
                __('The repository %s already exists.'), $dbfile
            ));
        }
        $return = 0;
        $output = array();
        $cmd = sprintf(
            Pluf::f('mtn_path', 'mtn').' db init -d %s',
            escapeshellarg($dbfile)
        );
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        $ll = exec($cmd, $output, $return);
        if ($return != 0) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not create repository %s - please check '.
                   'your error log for details.'),
                $dbfile
            ));
        }

        $usher_rc = file_get_contents($usher_config['rcfile']);
        $parsed_config = array();
        try {
            $parsed_config = IDF_Scm_Monotone_BasicIO::parse($usher_rc);
        }
        catch (Exception $e) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not parse usher configuration in "%s": %s'),
                $usher_config['rcfile'], $e->getMessage()
            ));
        }

        // ensure we haven't configured a server with this name already
        foreach ($parsed_config as $stanzas)
        {
            foreach ($stanzas as $stanza_line)
            {
                if ($stanza_line['key'] == 'server' &&
                    $stanza_line['values'][0] == $shortname)
                {
                    throw new IDF_Scm_Exception(sprintf(
                        __('usher configuration already contains a server '.
                           'entry named "%s"'),
                        $shortname
                    ));
                }
            }
        }

        $new_server = array(
            array('key' => 'server', 'values' => array($shortname)),
            array('key' => 'local', 'values' => array('-d', $dbfile)),
        );

        $parsed_config[] = $new_server;
        $usher_rc = IDF_Scm_Monotone_BasicIO::compile($parsed_config);

        // FIXME: more sanity - what happens on failing writes?
        $fp = fopen($usher_config['rcfile'], 'w');
        fwrite($fp, $usher_rc);
        fclose($fp);

        IDF_Scm_Monotone_Usher::reload();
    }
}
