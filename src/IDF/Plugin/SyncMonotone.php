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
            $plug->processProjectCreate($params['project']);
            break;
        case 'IDF_Project::membershipsUpdated':
            $plug->processMembershipsUpdated($params['project']);
            break;
        case 'IDF_Project::preDelete':
            $plug->processProjectDelete($params['project']);
            break;
        case 'IDF_Key::postSave':
            $plug->processKeyCreate($params['key']);
            break;
        case 'IDF_Key::preDelete':
            $plug->processKeyDelete($params['key']);
            break;
        case 'mtnpostpush.php::run':
            $plug->processSyncTimeline($params['project']);
            break;
        }
    }

    /**
     * Initial steps to setup a new monotone project:
     *
     *  1) run mtn db init to initialize a new database underknees
     *     'mtn_repositories'
     *  2) create a new server key in the same directory
     *  3) create a new client key for IDF and store it in the project conf
     *  4) write monotonerc
     *  5) add the database as new local server in the usher configuration
     *  6) reload the running usher instance so it acknowledges the new server
     *
     * The initial right setup happens in processMembershipsUpdated()
     *
     * @param IDF_Project
     */
    function processProjectCreate($project)
    {
        if ($project->getConf()->getVal('scm') != 'mtn') {
            return;
        }

        $projecttempl = Pluf::f('mtn_repositories', false);
        if ($projecttempl === false) {
            throw new IDF_Scm_Exception(
                 __('"mtn_repositories" must be defined in your configuration file.')
            );
        }

        $usher_config = Pluf::f('mtn_usher_conf', false);
        if (!$usher_config || !is_writable($usher_config)) {
            throw new IDF_Scm_Exception(
                 __('"mtn_usher_conf" does not exist or is not writable.')
            );
        }

        $mtnpostpush = realpath(dirname(__FILE__) . '/../../../scripts/mtn-post-push');
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
        $cmd = sprintf('db init -d %s', escapeshellarg($dbfile));
        self::_mtn_exec($cmd);

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

        $serverkey = $shortname.'-server@'.$server;
        $cmd = sprintf('au generate_key --confdir=%s %s ""',
            escapeshellarg($projectpath),
            escapeshellarg($serverkey)
        );
        self::_mtn_exec($cmd);

        //
        // step 3) create a client key, and save it in IDF
        //
        $clientkey_hash = '';
        $monotonerc_tpl = 'monotonerc-noauth.tpl';

        if (Pluf::f('mtn_remote_auth', true)) {
            $monotonerc_tpl = 'monotonerc-auth.tpl';
            $keydir = Pluf::f('tmp_folder').'/mtn-client-keys';
            if (!file_exists($keydir)) {
                if (!mkdir($keydir)) {
                    throw new IDF_Scm_Exception(sprintf(
                        __('The key directory %s could not be created.'), $keydir
                    ));
                }
            }

            $clientkey_name = $shortname.'-client@'.$server;
            $cmd = sprintf('au generate_key --keydir=%s %s ""',
                escapeshellarg($keydir),
                escapeshellarg($clientkey_name)
            );
            $keyinfo = self::_mtn_exec($cmd);

            $parsed_keyinfo = array();
            try {
                $parsed_keyinfo = IDF_Scm_Monotone_BasicIO::parse($keyinfo);
            }
            catch (Exception $e) {
                throw new IDF_Scm_Exception(sprintf(
                    __('Could not parse key information: %s'), $e->getMessage()
                ));
            }

            $clientkey_hash = $parsed_keyinfo[0][1]['hash'];
            $clientkey_file = $keydir . '/' . $clientkey_name . '.' . $clientkey_hash;
            $clientkey_data = file_get_contents($clientkey_file);

            $project->getConf()->setVal('mtn_client_key_name', $clientkey_name);
            $project->getConf()->setVal('mtn_client_key_hash', $clientkey_hash);
            $project->getConf()->setVal('mtn_client_key_data', $clientkey_data);

            // add the public client key to the server
            $cmd = sprintf('au get_public_key --keydir=%s %s',
                escapeshellarg($keydir),
                escapeshellarg($clientkey_hash)
            );
            $clientkey_pubdata = self::_mtn_exec($cmd);

            $cmd = sprintf('au put_public_key --db=%s %s',
                escapeshellarg($dbfile),
                escapeshellarg($clientkey_pubdata)
            );
            self::_mtn_exec($cmd);
        }

        //
        // step 4) write monotonerc
        //
        $monotonerc = file_get_contents(
            dirname(__FILE__).'/SyncMonotone/'.$monotonerc_tpl
        );
        $monotonerc = str_replace(
            array('%%MTNPOSTPUSH%%', '%%PROJECT%%', '%%MTNCLIENTKEY%%'),
            array($mtnpostpush, $shortname, $clientkey_hash),
            $monotonerc
        );

        $rcfile = $projectpath.'/monotonerc';

        if (file_put_contents($rcfile, $monotonerc, LOCK_EX) === false) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not write mtn configuration file "%s"'), $rcfile
            ));
        }

        //
        // step 5) read in and append the usher config with the new server
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
        foreach ($parsed_config as $stanzas) {
            foreach ($stanzas as $stanza_line) {
                if ($stanza_line['key'] == 'server' &&
                    $stanza_line['values'][0] == $shortname) {
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
        if (file_put_contents($usher_config, $usher_rc, LOCK_EX) === false) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not write usher configuration file "%s"'), $usher_config
            ));
        }

        //
        // step 6) reload usher to pick up the new configuration
        //
        IDF_Scm_Monotone_Usher::reload();
    }

    /**
     * Updates the read / write permissions for the monotone database
     *
     * @param IDF_Project
     */
    public function processMembershipsUpdated($project)
    {
        if ($project->getConf()->getVal('scm') != 'mtn') {
            return;
        }

        $mtn = IDF_Scm_Monotone::factory($project);
        $stdio = $mtn->getStdio();

        $projectpath = self::_get_project_path($project);
        $auth_ids    = self::_get_authorized_user_ids($project);
        $key_ids     = array();
        foreach ($auth_ids as $auth_id) {
            $sql = new Pluf_SQL('user=%s', array($auth_id));
            $keys = Pluf::factory('IDF_Key')->getList(array('filter' => $sql->gen()));
            foreach ($keys as $key) {
                if ($key->getType() != 'mtn')
                    continue;
                $stdio->exec(array('put_public_key', $key->content));
                $key_ids[] = $key->getMtnId();
            }
        }

        $write_permissions = implode("\n", $key_ids);
        $rcfile = $projectpath.'/write-permissions';
        if (file_put_contents($rcfile, $write_permissions, LOCK_EX) === false) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not write write-permissions file "%s"'), $rcfile
            ));
        }

        if ($project->private) {
            $stanza = array(
                array('key' => 'pattern', 'values' => array('*')),
            );
            foreach ($key_ids as $key_id)
            {
                $stanza[] = array('key' => 'allow', 'values' => array($key_id));
            }
        }
        else {
            $stanza = array(
                array('key' => 'pattern', 'values' => array('*')),
                array('key' => 'allow', 'values' => array('*')),
            );
        }
        $read_permissions = IDF_Scm_Monotone_BasicIO::compile(array($stanza));
        $rcfile = $projectpath.'/read-permissions';
        if (file_put_contents($rcfile, $read_permissions, LOCK_EX) === false) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not write read-permissions file "%s"'), $rcfile
            ));
        }
    }

    /**
     * Clean up after a mtn project was deleted
     *
     * @param IDF_Project
     */
    public function processProjectDelete($project)
    {
        if ($project->getConf()->getVal('scm') != 'mtn') {
            return;
        }

        $usher_config = Pluf::f('mtn_usher_conf', false);
        if (!$usher_config || !is_writable($usher_config)) {
            throw new IDF_Scm_Exception(
                 __('"mtn_usher_conf" does not exist or is not writable.')
            );
        }

        $shortname = $project->shortname;
        IDF_Scm_Monotone_Usher::killServer($shortname);

        $projecttempl = Pluf::f('mtn_repositories', false);
        if ($projecttempl === false) {
            throw new IDF_Scm_Exception(
                 __('"mtn_repositories" must be defined in your configuration file.')
            );
        }

        $projectpath = sprintf($projecttempl, $shortname);
        if (file_exists($projectpath)) {
            if (!self::_delete_recursive($projectpath)) {
                throw new IDF_Scm_Exception(sprintf(
                    __('One or more paths underknees %s could not be deleted.'), $projectpath
                ));
            }
        }

        if (Pluf::f('mtn_remote_auth', true)) {
            $keydir = Pluf::f('tmp_folder').'/mtn-client-keys';
            $keyname = $project->getConf()->getVal('mtn_client_key_name', false);
            $keyhash = $project->getConf()->getVal('mtn_client_key_hash', false);
            if ($keyname && $keyhash &&
                file_exists($keydir .'/'. $keyname . '.' . $keyhash)) {
                if (!@unlink($keydir .'/'. $keyname . '.' . $keyhash)) {
                    throw new IDF_Scm_Exception(sprintf(
                        __('Could not delete client private key %s'), $keyname
                    ));
                }
            }
        }

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

        foreach ($parsed_config as $idx => $stanzas) {
            foreach ($stanzas as $stanza_line) {
                if ($stanza_line['key'] == 'server' &&
                    $stanza_line['values'][0] == $shortname) {
                    unset($parsed_config[$idx]);
                    break;
                }
            }
        }

        $usher_rc = IDF_Scm_Monotone_BasicIO::compile($parsed_config);

        // FIXME: more sanity - what happens on failing writes? we do not
        // have a backup copy of usher.conf around...
        if (file_put_contents($usher_config, $usher_rc, LOCK_EX) === false) {
            throw new IDF_Scm_Exception(sprintf(
                __('Could not write usher configuration file "%s"'), $usher_config
            ));
        }

        IDF_Scm_Monotone_Usher::reload();
    }

    /**
     * Adds the (monotone) key to all monotone projects of this forge
     * where the user of the key has write access to
     */
    public function processKeyCreate($key)
    {
        if ($key->getType() != 'mtn')
            return;

        foreach (Pluf::factory('IDF_Project')->getList() as $project) {
            $conf = new IDF_Conf();
            $conf->setProject($project);
            $scm = $conf->getVal('scm', 'mtn');
            if ($scm != 'mtn')
                continue;

            $projectpath = self::_get_project_path($project);
            $auth_ids    = self::_get_authorized_user_ids($project);
            if (!in_array($key->user, $auth_ids))
                continue;

            $mtn_key_id = $key->getMtnId();

            // if the project is not defined as private, all people have
            // read access already, so we don't need to write anything
            // and we currently do not check if read-permissions really
            // contains
            //      pattern "*"
            //      allow "*"
            // which is the default for non-private projects
            if ($project->private == true) {
                $read_perms = file_get_contents($projectpath.'/read-permissions');
                $parsed_read_perms = array();
                try {
                    $parsed_read_perms = IDF_Scm_Monotone_BasicIO::parse($read_perms);
                }
                catch (Exception $e) {
                    throw new IDF_Scm_Exception(sprintf(
                        __('Could not parse read-permissions for project "%s": %s'),
                        $shortname, $e->getMessage()
                    ));
                }

                $wildcard_section = null;
                for ($i=0; $i<count($parsed_read_perms); ++$i) {
                    foreach ($parsed_read_perms[$i] as $stanza_line) {
                        if ($stanza_line['key'] == 'pattern' &&
                            $stanza_line['values'][0] == '*') {
                            $wildcard_section =& $parsed_read_perms[$i];
                            break;
                        }
                    }
                }

                if ($wildcard_section == null)
                {
                    $wildcard_section = array(
                        array('key' => 'pattern', 'values' => array('*'))
                    );
                    $parsed_read_perms[] =& $wildcard_section;
                }

                $key_found = false;
                foreach ($wildcard_section as $line)
                {
                    if ($line['key'] == 'allow' && $line['values'][0] == $mtn_key_id) {
                        $key_found = true;
                        break;
                    }
                }

                if (!$key_found) {
                    $wildcard_section[] = array(
                        'key' => 'allow', 'values' => array($mtn_key_id)
                    );
                }

                $read_perms = IDF_Scm_Monotone_BasicIO::compile($parsed_read_perms);

                if (file_put_contents($projectpath.'/read-permissions',
                                      $read_perms, LOCK_EX) === false) {
                    throw new IDF_Scm_Exception(sprintf(
                        __('Could not write read-permissions for project "%s"'), $shortname
                    ));
                }
            }

            $write_perms = file_get_contents($projectpath.'/write-permissions');
            $lines = preg_split("/(\n|\r\n)/", $write_perms, -1, PREG_SPLIT_NO_EMPTY);
            if (!in_array('*', $lines) && !in_array($mtn_key_id, $lines)) {
                $lines[] = $mtn_key_id;
            }
            if (file_put_contents($projectpath.'/write-permissions',
                                  implode("\n", $lines) . "\n", LOCK_EX) === false) {
                throw new IDF_Scm_Exception(sprintf(
                    __('Could not write write-permissions file for project "%s"'),
                    $shortname
                ));
            }

            $mtn = IDF_Scm_Monotone::factory($project);
            $stdio = $mtn->getStdio();
            $stdio->exec(array('put_public_key', $key->content));
        }
    }

    /**
     * Removes the (monotone) key from all monotone projects of this forge
     * where the user of the key has write access to
     */
    public function processKeyDelete($key)
    {
        if ($key->getType() != 'mtn')
            return;

        foreach (Pluf::factory('IDF_Project')->getList() as $project) {
            $conf = new IDF_Conf();
            $conf->setProject($project);
            $scm = $conf->getVal('scm', 'mtn');
            if ($scm != 'mtn')
                continue;

            $projectpath = self::_get_project_path($project);
            $auth_ids    = self::_get_authorized_user_ids($project);
            if (!in_array($key->user, $auth_ids))
                continue;

            $mtn_key_id = $key->getMtnId();

            // if the project is not defined as private, all people have
            // read access already, so we don't need to write anything
            // and we currently do not check if read-permissions really
            // contains
            //      pattern "*"
            //      allow "*"
            // which is the default for non-private projects
            if ($project->private) {
                $read_perms = file_get_contents($projectpath.'/read-permissions');
                $parsed_read_perms = array();
                try {
                    $parsed_read_perms = IDF_Scm_Monotone_BasicIO::parse($read_perms);
                }
                catch (Exception $e) {
                    throw new IDF_Scm_Exception(sprintf(
                        __('Could not parse read-permissions for project "%s": %s'),
                        $shortname, $e->getMessage()
                    ));
                }

                // while we add new keys only to an existing wild-card entry
                // we remove dropped keys from all sections since the key
                // should be simply unavailable for all of them
                for ($h=0; $h<count($parsed_read_perms); ++$h) {
                    for ($i=0; $i<count($parsed_read_perms[$h]); ++$i) {
                        if ($parsed_read_perms[$h][$i]['key'] == 'allow' &&
                            $parsed_read_perms[$h][$i]['values'][0] == $mtn_key_id) {
                            unset($parsed_read_perms[$h][$i]);
                            continue;
                        }
                    }
                }

                $read_perms = IDF_Scm_Monotone_BasicIO::compile($parsed_read_perms);

                if (file_put_contents($projectpath.'/read-permissions',
                                      $read_perms, LOCK_EX) === false) {
                    throw new IDF_Scm_Exception(sprintf(
                        __('Could not write read-permissions for project "%s"'), $shortname
                    ));
                }
            }

            $write_perms = file_get_contents($projectpath.'/write-permissions');
            $lines = preg_split("/(\n|\r\n)/", $write_perms, -1, PREG_SPLIT_NO_EMPTY);
            for ($i=0; $i<count($lines); ++$i) {
                if ($lines[$i] == $mtn_key_id) {
                    unset($lines[$i]);
                    // the key should actually only exist once in the
                    // file, but we're paranoid
                    continue;
                }
            }
            if (file_put_contents($projectpath.'/write-permissions',
                                  implode("\n", $lines) . "\n", LOCK_EX) === false) {
                throw new IDF_Scm_Exception(sprintf(
                    __('Could not write write-permissions file for project "%s"'),
                    $shortname
                ));
            }

            $mtn = IDF_Scm_Monotone::factory($project);
            $stdio = $mtn->getStdio();
            // if the public key did not sign any revisions, drop it from
            // the database as well
            try {
                if (strlen($stdio->exec(array('select', 'k:' . $mtn_key_id))) == 0) {
                    $stdio->exec(array('drop_public_key', $mtn_key_id));
                }
            } catch (IDF_Scm_Exception $e) {
                if (strpos($e->getMessage(), 'there is no key named') === false)
                    throw $e;
            }
        }
    }

    /**
     * Update the timeline after a push
     *
     */
    public function processSyncTimeline($project_name)
    {
        try {
            $project = IDF_Project::getOr404($project_name);
        } catch (Pluf_HTTP_Error404 $e) {
            Pluf_Log::event(array(
                'IDF_Plugin_SyncMonotone::processSyncTimeline',
                'Project not found.',
                array($project_name, $params)
            ));
            return false; // Project not found
        }

        Pluf_Log::debug(array(
            'IDF_Plugin_SyncMonotone::processSyncTimeline',
            'Project found', $project_name, $project->id
        ));
        IDF_Scm::syncTimeline($project, true);
        Pluf_Log::event(array(
            'IDF_Plugin_SyncMonotone::processSyncTimeline',
            'sync', array($project_name, $project->id)
        ));
    }

    private static function _get_authorized_user_ids($project)
    {
        $mem = $project->getMembershipData();
        $members = array_merge((array)$mem['members'],
                               (array)$mem['owners'],
                               (array)$mem['authorized']);
        $userids = array();
        foreach ($members as $member) {
            $userids[] = $member->id;
        }
        return $userids;
    }

    private static function _get_project_path($project)
    {
        $projecttempl = Pluf::f('mtn_repositories', false);
        if ($projecttempl === false) {
            throw new IDF_Scm_Exception(
                 __('"mtn_repositories" must be defined in your configuration file.')
            );
        }

        $projectpath = sprintf($projecttempl, $project->shortname);
        if (!file_exists($projectpath)) {
            throw new IDF_Scm_Exception(sprintf(
                __('The project path %s does not exists.'), $projectpath
            ));
        }
        return $projectpath;
    }

    private static function _mtn_exec($cmd)
    {
        $fullcmd = sprintf('%s %s %s',
            Pluf::f('idf_exec_cmd_prefix', ''),
            Pluf::f('mtn_path', 'mtn'),
            $cmd
        );

        $output = $return = null;
        exec($fullcmd, $output, $return);
        if ($return != 0) {
            throw new IDF_Scm_Exception(sprintf(
                __('The command "%s" could not be executed.'), $cmd
            ));
        }
        return implode("\n", $output);
    }

    private static function _delete_recursive($path)
    {
        if (is_file($path)) {
            return @unlink($path);
        }

        if (is_dir($path)) {
            $scan = glob(rtrim($path, '/') . '/*');
            $status = 0;
            foreach ($scan as $subpath) {
                $status |= self::_delete_recursive($subpath);
            }
            $status |= rmdir($path);
            return $status;
        }
    }
}
