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
        case 'mtnpostpush.php::run':
            $plug->processSyncTimeline($params);
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

        $mtnpostpush = realpath(dirname(__FILE__) . "/../../../scripts/mtn-post-push");
        if (!file_exists($mtnpostpush)) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not find mtn-post-push script "%s".'), $mtnpostpush
            ));
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
        $output = $return = null;
        $ll = exec($cmd, $output, $return);
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
            Pluf::f('mtn_path', 'mtn').' au generate_key --confdir=%s %s ""',
            escapeshellarg($projectpath),
            escapeshellarg($keyname)
        );

        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        $output = $return = null;
        $ll = exec($cmd, $output, $return);
        if ($return != 0) {
            throw new IDF_Scm_Exception(sprintf(
                __('The server key %s could not be created.'), $keyname
            ));
        }

        //
        // step 3) write monotonerc for access control
        //         FIXME: netsync access control is still missing!
        //    
        $monotonerc = file_get_contents(dirname(__FILE__) . "/SyncMonotone/monotonerc.tpl");
        $monotonerc = str_replace(
            array("%%MTNPOSTPUSH%%", "%%PROJECT%%"),
            array($mtnpostpush, $shortname),
            $monotonerc
        );

        $rcfile = $projectpath.'/monotonerc';

        if (!file_put_contents($rcfile, $monotonerc, LOCK_EX)) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not write mtn configuration file "%s"'), $rcfile
            ));
        }

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

        // FIXME: more sanity - what happens on failing writes? we do not
        // have a backup copy of usher.conf around...
        if (!file_put_contents($usher_config, $usher_rc, LOCK_EX)) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not write usher configuration file "%s"'), $usher_config
            ));
        }

        //
        // step 5) reload usher to pick up the new configuration
        //
        IDF_Scm_Monotone_Usher::reload();
    }

    /**
     * Update the timeline after a push
     *
     */
    public function processSyncTimeline($params)
    {
        $pname = $params['project'];
        try {
            $project = IDF_Project::getOr404($pname);
        } catch (Pluf_HTTP_Error404 $e) {
            Pluf_Log::event(array(
                'IDF_Plugin_SyncMonotone::processSyncTimeline', 
                'Project not found.',
                array($pname, $params)
            ));
            return false; // Project not found
        }

        Pluf_Log::debug(array(
            'IDF_Plugin_SyncMonotone::processSyncTimeline', 
            'Project found', $pname, $project->id
        ));
        IDF_Scm::syncTimeline($project, true);
        Pluf_Log::event(array(
            'IDF_Plugin_SyncMonotone::processSyncTimeline',
            'sync', array($pname, $project->id)
        ));
    }
}
