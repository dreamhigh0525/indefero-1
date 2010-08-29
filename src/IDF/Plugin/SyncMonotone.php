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
     * Four steps to setup a new monotone project:
     *
     *  1) run mtn db init to initialize a new database underknees
     *     'mtn_repositories'
     *  2) create a new server key in the same directory
     *  3) write monotonerc for access control
     *  4) add the database as new local server in the usher configuration
     *  5) reload the running usher instance so it acknowledges the new
     *     server
     *
     * @param IDF_Project
     */
    function processMonotoneCreate($project)
    {
        if ($project->getConf()->getVal('scm') != 'mtn') {
            return;
        }

        $projecttempl = Pluf::f('mtn_repositories', false);
        if ($projecttempl === false) {
            throw new IDF_Scm_Exception(
                 '"mtn_repositories" must be defined in your configuration file.'
            );
        }

        $usher_config = Pluf::f('mtn_usher_conf', false);
        if (!$usher_config || !is_writable($usher_config)) {
            throw new IDF_Scm_Exception(
                 '"mtn_usher_conf" does not exist or is not writable.'
            );
        }

        $shortname = $project->shortname;
        $projectpath = sprintf($projecttempl, $shortname);
        if (file_exists($projectpath)) {
            throw new IDF_Scm_Exception(sprintf(
                __('The project path %s already exists.'), $projectpath
            ));
        }

        if (!mkdir($projectpath)) {
            throw new IDF_Scm_Exception(sprintf(
                __('The project path %s could not be created.'), $projectpath
            ));
        }

        //
        // step 1) create a new database
        //
        $dbfile = $projectpath.'/database.mtn';
        $cmd = sprintf(
            Pluf::f('mtn_path', 'mtn').' db init -d %s',
            escapeshellarg($dbfile)
        );
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        $ll = exec($cmd, $output = array(), $return = 0);
        if ($return != 0) {
            throw new IDF_Scm_Exception(sprintf(
                __('The database file %s could not be created.'), $dbfile
            ));
        }

        //
        // step 2) create a server key
        //
        // try to parse the key's domain part from the remote_url's host
        // name, otherwise fall back to the configured Apache server name
        $server = $_SERVER['SERVER_NAME'];
        $remote_url = Pluf::f('mtn_remote_url');
        if (($parsed = parse_url($remote_url)) !== false &&
            !empty($parsed['host'])) {
            $server = $parsed['host'];
        }

        $keyname = $shortname.'-server@'.$server;
        $cmd = sprintf(
            Pluf::f('mtn_path', 'mtn').' au genkey --confdir=%s %s ""',
            escapeshellarg($projectpath),
            escapeshellarg($keyname)
        );
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        $ll = exec($cmd, $output = array(), $return = 0);
        if ($return != 0) {
            throw new IDF_Scm_Exception(sprintf(
                __('The server key %s could not be created.'), $keyname
            ));
        }

        //
        // step 3) write monotonerc for access control
        //         FIXME: netsync access control is still missing!
        //
        $monotonerc =<<<END
function get_remote_automate_permitted(key_identity, command, options)
    local read_only_commands = {
        "get_corresponding_path", "get_content_changed", "tags", "branches",
        "common_ancestors", "packet_for_fdelta", "packet_for_fdata",
        "packets_for_certs", "packet_for_rdata", "get_manifest_of",
        "get_revision", "select", "graph", "children", "parents", "roots",
        "leaves", "ancestry_difference", "toposort", "erase_ancestors",
        "descendents", "ancestors", "heads", "get_file_of", "get_file",
        "interface_version", "get_attributes", "content_diff",
        "file_merge", "show_conflicts", "certs", "keys"
    }

    for _,v in ipairs(read_only_commands) do
        if (v == command[1]) then
            return true
        end
    end

    return false
end
END;
        $rcfile = $projectpath.'/monotonerc';

        // FIXME: sanity
        $fp = fopen($rcfile, 'w');
        fwrite($fp, $monotonerc);
        fclose($fp);

        //
        // step 4) read in and append the usher config with the new server
        //
        $usher_rc = file_get_contents($usher_config);
        $parsed_config = array();
        try {
            $parsed_config = IDF_Scm_Monotone_BasicIO::parse($usher_rc);
        }
        catch (Exception $e) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not parse usher configuration in "%s": %s'),
                $usher_config, $e->getMessage()
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
            array('key' => 'local', 'values' => array(
                '--confdir', $projectpath,
                '-d', $dbfile
            )),
        );

        $parsed_config[] = $new_server;
        $usher_rc = IDF_Scm_Monotone_BasicIO::compile($parsed_config);

        // FIXME: more sanity - what happens on failing writes?
        $fp = fopen($usher_config, 'w');
        fwrite($fp, $usher_rc);
        fclose($fp);

        //
        // step 5) reload usher to pick up the new configuration
        //
        IDF_Scm_Monotone_Usher::reload();
    }
}
