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
 * Git utils.
 *
 */
class IDF_Scm_Git extends IDF_Scm
{
    public $mediumtree_fmt = 'commit %H%nAuthor: %an <%ae>%nTree: %T%nParents: %P%nDate: %ai%n%n%s%n%n%b';

    /* ============================================== *
     *                                                *
     *   Common Methods Implemented By All The SCMs   *
     *                                                *
     * ============================================== */

    public function __construct($repo, $project=null)
    {
        $this->repo = $repo;
        $this->project = $project;
    }

    public function getRepositorySize()
    {
        if (!file_exists($this->repo)) {
            return 0;
        }
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').'du -sk '
            .escapeshellarg($this->repo);
        $out = explode(' ',
                       self::shell_exec('IDF_Scm_Git::getRepositorySize', $cmd),
                       2);
        return (int) $out[0]*1024;
    }

    public function isAvailable()
    {
        try {
            $branches = $this->getBranches();
        } catch (IDF_Scm_Exception $e) {
            return false;
        }
        return (count($branches) > 0);
    }

    public function getBranches()
    {
        if (isset($this->cache['branches'])) {
            return $this->cache['branches'];
        }
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' branch',
                     escapeshellarg($this->repo));
        self::exec('IDF_Scm_Git::getBranches',
                   $cmd, $out, $return);
        if ($return != 0) {
            throw new IDF_Scm_Exception(sprintf($this->error_tpl,
                                                $cmd, $return,
                                                implode("\n", $out)));
        }
        $res = array();
        foreach ($out as $b) {
            $b = substr($b, 2);
            if (false !== strpos($b, '/')) {
                $res[$this->getCommit($b)->commit] = $b;
            } else {
                $res[$b] = '';
            }
        }
        $this->cache['branches'] = $res;
        return $res;
    }

    public function getMainBranch()
    {
        $branches = $this->getBranches();
        if (array_key_exists('master', $branches))
            return 'master';
        static $possible = array('main', 'trunk', 'local');
        for ($i = 0; 3 > $i; ++$i) {
            if (array_key_exists($possible[$i], $branches))
                return $possible[$i];
        }
        return key($branches);
    }

    /**
     * Note: Running the `git branch --contains $commit` is
     * theoritically the best way to do it, until you figure out that
     * you cannot cache the result and that it takes several seconds
     * to execute on a big tree.
     */
    public function inBranches($commit, $path)
    {
        return $this->_inObject($commit, 'branch');
    }

    /**
     * Will find the parents if available.
     */
    public function getExtraProperties($obj)
    {
        return (isset($obj->parents)) ? array('parents' => $obj->parents) : array();
    }

    /**
     * @see IDF_Scm::getTags()
     **/
    public function getTags()
    {
        if (isset($this->cache['tags'])) {
            return $this->cache['tags'];
        }
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf('GIT_DIR=%s %s for-each-ref --format="%%(taggerdate:iso)%%(committerdate:iso) %%(objectname) %%(refname)" refs/tags',
                     escapeshellarg($this->repo),
                     Pluf::f('git_path', 'git'));
        self::exec('IDF_Scm_Git::getTags', $cmd, $out, $return);
        if (0 != $return) {
            throw new IDF_Scm_Exception(sprintf($this->error_tpl,
                                                $cmd, $return,
                                                implode("\n", $out)));
        }
        rsort($out);
        $res = array();
        foreach ($out as $b) {
            $elts = explode(' ', $b, 5);
            $tag = substr(trim($elts[4]), 10);
            if (false !== strpos($tag, '/')) {
                $res[$elts[3]] = $b;
            } else {
                $res[$tag] = '';
            }
        }
        $this->cache['tags'] = $res;
        return $res;
    }

    /**
     * @see IDF_Scm::inTags()
     **/
    public function inTags($commit, $path)
    {
        return $this->_inObject($commit, 'tag');
    }

    /**
     * Returns in which branches or tags a commit is.
     *
     * @param string Commit
     * @param string Object's type: 'branch' or 'tag'.
     * @return array
     */
    private function _inObject($commit, $object)
    {
        $object = strtolower($object);
        if ('branch' === $object) {
            $objects = $this->getBranches();
        } else if ('tag' === $object) {
            $objects = $this->getTags();
        } else {
            throw new InvalidArgumentException(sprintf(__('Invalid value for the parameter %1$s: %2$s. Use %3$s.'),
                                                       '$object',
                                                       $object,
                                                       '\'branch\' or \'tag\''));
        }
        unset($object);
        $result = array();
        if (array_key_exists($commit, $objects)) {
            $result[] = $commit;
        }
        return $result;
    }

    /**
     * Git "tree" is not the same as the tree we get here.
     *
     * With git each commit object stores a related tree object. This
     * tree is basically providing what is in the given folder at the
     * given commit. It looks something like that:
     *
     * <pre>
     * 100644 blob bcd155e609c51b4651aab9838b270cce964670af	AUTHORS
     * 100644 blob 87b44c5c7df3cc90c031317c1ac8efcfd8a13631	COPYING
     * 100644 blob 2a0f899cbfe33ea755c343b06a13d7de6c22799f	INSTALL.mdtext
     * 040000 tree 2f469c4c5318aa4ad48756874373370f6112f77b	doc
     * 040000 tree 911e0bd2706f0069b04744d6ef41353faf06a0a7	logo
     * </pre>
     *
     * You can then follow what is in the given folder (let say doc)
     * by using the hash.
     *
     * This means that you will have not to confuse the git tree and
     * the output tree in the following method.
     *
     * @see http://www.kernel.org/pub/software/scm/git/docs/git-ls-tree.html
     *
     */
    public function getTree($commit, $folder='/', $branch=null)
    {
        $folder = ($folder == '/') ? '' : $folder;
        // now we grab the info about this commit including its tree.
        if (false == ($co = $this->getCommit($commit))) {
            return false;
        }
        if ($folder) {
            // As we are limiting to a given folder, we need to find
            // the tree corresponding to this folder.
            $tinfo = $this->getTreeInfo($commit, $folder);
            if (isset($tinfo[0]) and $tinfo[0]->type == 'tree') {
                $tree = $tinfo[0]->hash;
            } else {
                throw new Exception(sprintf(__('Folder %1$s not found in commit %2$s.'), $folder, $commit));
            }
        } else {
            $tree = $co->tree;
        }
        $res = array();
        foreach ($this->getTreeInfo($tree) as $file) {
            // Now we grab the files in the current tree with as much
            // information as possible.
            if ($file->type == 'blob') {
                $file->date = $co->date;
                $file->log = '----';
                $file->author = 'Unknown';
            }
            $file->fullpath = ($folder) ? $folder.'/'.$file->file : $file->file;
            $file->efullpath = self::smartEncode($file->fullpath);
            if ($file->type == 'commit') {
                // We have a submodule
                $file = $this->getSubmodule($file, $commit);
            }
            $res[] = $file;
        }
        // Grab the details for each blob and return the list.
        return $this->getTreeDetails($res);
    }

    /**
     * Given the string describing the author from the log find the
     * author in the database.
     *
     * @param string Author
     * @return mixed Pluf_User or null
     */
    public function findAuthor($author)
    {
        // We extract the email.
        $match = array();
        if (!preg_match('/<(.*)>/', $author, $match)) {
            return null;
        }
        $sql = new Pluf_SQL('login=%s', array($match[1]));
        $users = Pluf::factory('Pluf_User')->getList(array('filter'=>$sql->gen()));
        if ($users->count() > 0) {
            return $users[0];
        }
        return Pluf::factory('IDF_EmailAddress')->get_user_for_email_address($match[1]);
    }

    public static function getAnonymousAccessUrl($project, $commit=null)
    {
        return sprintf(Pluf::f('git_remote_url'), $project->shortname);
    }

    public static function getAuthAccessUrl($project, $user, $commit=null)
    {
        // if the user haven't registred a public ssh key,
        // he can't use the write url which use the SSH authentification
        if ($user != null) {
            $keys = $user->get_idf_key_list();
            if (count ($keys) == 0)
                return self::getAnonymousAccessUrl($project);
        } 
        
        return sprintf(Pluf::f('git_write_remote_url'), $project->shortname);
    }

    /**
     * Returns this object correctly initialized for the project.
     *
     * @param IDF_Project
     * @return IDF_Scm_Git
     */
    public static function factory($project)
    {
        $rep = sprintf(Pluf::f('git_repositories'), $project->shortname);
        return new IDF_Scm_Git($rep, $project);
    }


    public function validateRevision($commit)
    {
        $type = $this->testHash($commit);
        if ('commit' == $type || 'tag' == $type)
            return IDF_Scm::REVISION_VALID;
        return IDF_Scm::REVISION_INVALID;
    }

    /**
     * Test a given object hash.
     *
     * @param string Object hash.
     * @return mixed false if not valid or 'blob', 'tree', 'commit', 'tag'
     */
    public function testHash($hash)
    {
        $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' cat-file -t %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($hash));
        $ret = 0; $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Git::testHash', $cmd, $out, $ret);
        if ($ret != 0) return false;
        return trim($out[0]);
    }

    /**
     * Get the tree info.
     *
     * @param string Tree hash
     * @param bool Do we recurse in subtrees (true)
     * @param string Folder in which we want to get the info ('')
     * @return array Array of file information.
     */
    public function getTreeInfo($tree, $folder='')
    {
        if (!in_array($this->testHash($tree), array('tree', 'commit', 'tag'))) {
            throw new Exception(sprintf(__('Not a valid tree: %s.'), $tree));
        }
        $cmd_tmpl = 'GIT_DIR=%s '.Pluf::f('git_path', 'git').' ls-tree -l %s %s';
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf($cmd_tmpl, escapeshellarg($this->repo),
                     escapeshellarg($tree), escapeshellarg($folder));
        $out = array();
        $res = array();
        self::exec('IDF_Scm_Git::getTreeInfo', $cmd, $out);
        foreach ($out as $line) {
            list($perm, $type, $hash, $size, $file) = preg_split('/ |\t/', $line, 5, PREG_SPLIT_NO_EMPTY);
            $res[] = (object) array('perm' => $perm, 'type' => $type,
                                    'size' => $size, 'hash' => $hash,
                                    'file' => $file);
        }
        return $res;
    }

    /**
     * Get the file info.
     *
     * @param string File
     * @param string Commit ('HEAD')
     * @return false Information
     */
    public function getPathInfo($totest, $commit='HEAD')
    {
        $cmd_tmpl = 'GIT_DIR=%s '.Pluf::f('git_path', 'git').' ls-tree -r -t -l %s';
        $cmd = sprintf($cmd_tmpl,
                       escapeshellarg($this->repo),
                       escapeshellarg($commit));
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Git::getPathInfo', $cmd, $out);
        foreach ($out as $line) {
            list($perm, $type, $hash, $size, $file) = preg_split('/ |\t/', $line, 5, PREG_SPLIT_NO_EMPTY);
            if ($totest == $file) {
                $pathinfo = pathinfo($file);
                return (object) array('perm' => $perm, 'type' => $type,
                                      'size' => $size, 'hash' => $hash,
                                      'fullpath' => $file,
                                      'file' => $pathinfo['basename']);
            }
        }
        return false;
    }

    public function getFile($def, $cmd_only=false)
    {
        $cmd = sprintf(Pluf::f('idf_exec_cmd_prefix', '').
                       'GIT_DIR=%s '.Pluf::f('git_path', 'git').' cat-file blob %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($def->hash));
        return ($cmd_only)
            ? $cmd : self::shell_exec('IDF_Scm_Git::getFile', $cmd);
    }

    /**
     * Get commit details.
     *
     * @param string Commit
     * @param bool Get commit diff (false)
     * @return array Changes
     */
    public function getCommit($commit, $getdiff=false)
    {
        if ($getdiff) {
            $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' show --date=iso --pretty=format:%s %s',
                           escapeshellarg($this->repo),
                           "'".$this->mediumtree_fmt."'",
                           escapeshellarg($commit));
        } else {
            $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log -1 --date=iso --pretty=format:%s %s',
                           escapeshellarg($this->repo),
                           "'".$this->mediumtree_fmt."'",
                           escapeshellarg($commit));
        }
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Git::getCommit', $cmd, $out, $ret);
        if ($ret != 0 or count($out) == 0) {
            return false;
        }
        if ($getdiff) {
            $log = array();
            $change = array();
            $inchange = false;
            foreach ($out as $line) {
                if (!$inchange and 0 === strpos($line, 'diff --git a')) {
                    $inchange = true;
                }
                if ($inchange) {
                    $change[] = $line;
                } else {
                    $log[] = $line;
                }
            }
            $out = self::parseLog($log);
            $out[0]->diff = implode("\n", $change);
        } else {
            $out = self::parseLog($out);
            $out[0]->diff = '';
        }

        $out[0]->branch = implode(', ', $this->inBranches($commit, null));
        return $out[0];
    }

    /**
     * Check if a commit is big.
     *
     * @param string Commit ('HEAD')
     * @return bool The commit is big
     */
    public function isCommitLarge($commit='HEAD')
    {
        $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log --numstat -1 --pretty=format:%s %s',
                       escapeshellarg($this->repo),
                       "'commit %H%n'",
                       escapeshellarg($commit));
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Git::isCommitLarge', $cmd, $out);
        $affected = count($out) - 2;
        $added = 0;
        $removed = 0;
        $c=0;
        foreach ($out as $line) {
            $c++;
            if ($c < 3) {
                continue;
            }
            list($a, $r, $f) = preg_split("/[\s]+/", $line, 3, PREG_SPLIT_NO_EMPTY);
            $added+=$a;
            $removed+=$r;
        }
        return ($affected > 100 or ($added + $removed) > 20000);
    }

    /**
     * Get latest changes.
     *
     * @param string Commit ('HEAD').
     * @param int Number of changes (10).
     * @return array Changes.
     */
    public function getChangeLog($commit='HEAD', $n=10)
    {
        if ($n === null) $n = '';
        else $n = ' -'.$n;
        $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log%s --date=iso --pretty=format:\'%s\' %s',
                       escapeshellarg($this->repo), $n, $this->mediumtree_fmt,
                       escapeshellarg($commit));
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Git::getChangeLog', $cmd, $out);
        return self::parseLog($out);
    }

    /**
     * Parse the log lines of a --pretty=medium log output.
     *
     * @param array Lines.
     * @return array Change log.
     */
    public static function parseLog($lines)
    {
        $res = array();
        $c = array();
        $inheads = true;
        $next_is_title = false;
        foreach ($lines as $line) {
            if (preg_match('/^commit (\w{40})$/', $line)) {
                if (count($c) > 0) {
                    $c['full_message'] = trim($c['full_message']);
                    $c['full_message'] = IDF_Commit::toUTF8($c['full_message']);
                    $c['title'] = IDF_Commit::toUTF8($c['title']);
                    if (isset($c['parents'])) {
                        $c['parents'] = explode(' ', trim($c['parents']));
                    }
                    $res[] = (object) $c;
                }
                $c = array();
                $c['commit'] = trim(substr($line, 7, 40));
                $c['full_message'] = '';
                $inheads = true;
                $next_is_title = false;
                continue;
            }
            if ($next_is_title) {
                $c['title'] = trim($line);
                $next_is_title = false;
                continue;
            }
            $match = array();
            if ($inheads and preg_match('/(\S+)\s*:\s*(.*)/', $line, $match)) {
                $match[1] = strtolower($match[1]);
                $c[$match[1]] = trim($match[2]);
                if ($match[1] == 'date') {
                    $c['date'] = gmdate('Y-m-d H:i:s', strtotime($match[2]));
                }
                continue;
            }
            if ($inheads and !$next_is_title and $line == '') {
                $next_is_title = true;
                $inheads = false;
            }
            if (!$inheads) {
                $c['full_message'] .= trim($line)."\n";
                continue;
            }
        }
        $c['full_message'] = !empty($c['full_message']) ? trim($c['full_message']) : '';
        $c['full_message'] = IDF_Commit::toUTF8($c['full_message']);
        $c['title'] = IDF_Commit::toUTF8($c['title']);
        if (isset($c['parents'])) {
            $c['parents'] = explode(' ', trim($c['parents']));
        }
        $res[] = (object) $c;
        return $res;
    }

    public function getArchiveStream($commit, $prefix='repository/')
    {
        $cmd = sprintf(Pluf::f('idf_exec_cmd_prefix', '').
                       'GIT_DIR=%s '.Pluf::f('git_path', 'git').' archive --format=zip --prefix=%s %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($prefix),
                       escapeshellarg($commit));
        return new Pluf_HTTP_Response_CommandPassThru($cmd, 'application/x-zip');
    }

    /*
     * =====================================================
     *             Specific Git Commands
     * =====================================================
     */

    /**
     * Get submodule details.
     *
     * Given a "commit" file in the tree, find the submodule details.
     *
     * @param stdClass File description of the module
     * @param string Current commit
     * @return stdClass File description
     */
    public function getSubmodule($file, $commit)
    {
        $file->type = 'extern';
        $file->extern = '';
        $info = $this->getPathInfo('.gitmodules', $commit);
        if ($info == false) {
            return $file;
        }
        $gitmodules = $this->getFile($info);
        if (preg_match('#\[submodule\s+\"'.$file->fullpath.'\"\]\s+path\s=\s(\S+)\s+url\s=\s(\S+)#mi', $gitmodules, $matches)) {
            $file->extern = $matches[2];
        }
        return $file;
    }

    /**
     * Foreach file in the tree, find the details.
     *
     * @param array Tree information
     * @return array Updated tree information
     */
    public function getTreeDetails($tree)
    {
        $n = count($tree);
        $details = array();
        for ($i=0;$i<$n;$i++) {
            if ($tree[$i]->type == 'blob') {
                $details[$tree[$i]->hash] = $i;
            }
        }
        if (!count($details)) {
            return $tree;
        }
        $res = $this->getCachedBlobInfo($details);
        $toapp = array();
        foreach ($details as $blob => $idx) {
            if (isset($res[$blob])) {
                $tree[$idx]->date = $res[$blob]->date;
                $tree[$idx]->log = $res[$blob]->title;
                $tree[$idx]->author = $res[$blob]->author;
            } else {
                $toapp[$blob] = $idx;
            }
        }
        if (count($toapp)) {
            $res = $this->appendBlobInfoCache($toapp);
            foreach ($details as $blob => $idx) {
                if (isset($res[$blob])) {
                    $tree[$idx]->date = $res[$blob]->date;
                    $tree[$idx]->log = $res[$blob]->title;
                    $tree[$idx]->author = $res[$blob]->author;
                }
            }
        }
        return $tree;
    }

    /**
     * Append build info cache.
     *
     * The append method tries to get only the necessary details, so
     * instead of going through all the commits one at a time, it will
     * try to find a smarter way with regex.
     *
     * @see self::buildBlobInfoCache
     *
     * @param array The blob for which we need the information
     * @return array The information
     */
    public function appendBlobInfoCache($blobs)
    {
        $rawlog = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log --raw --abbrev=40 --pretty=oneline -5000 --skip=%%s',
                     escapeshellarg($this->repo));
        $skip = 0;
        $res = array();
        self::exec('IDF_Scm_Git::appendBlobInfoCache',
                   sprintf($cmd, $skip), $rawlog);
        while (count($rawlog) and count($blobs)) {
            $rawlog = implode("\n", array_reverse($rawlog));
            foreach ($blobs as $blob => $idx) {
                if (preg_match('/^\:\d{6} \d{6} [0-9a-f]{40} '
                               .$blob.' .*^([0-9a-f]{40})/msU',
                               $rawlog, $matches)) {
                    $fc = $this->getCommit($matches[1]);
                    $res[$blob] = (object) array('hash' => $blob,
                                                 'date' => $fc->date,
                                                 'title' => $fc->title,
                                                 'author' => $fc->author);
                    unset($blobs[$blob]);
                }
            }
            $rawlog = array();
            $skip += 5000;
            if ($skip > 20000) {
                // We are in the case of the import of a big old
                // repository, we can store as unknown the commit info
                // not to try to retrieve them each time.
                foreach ($blobs as $blob => $idx) {
                    $res[$blob] = (object) array('hash' => $blob,
                                                 'date' => '0',
                                                 'title' => '----',
                                                 'author' => 'Unknown');
                }
                break;
            }
            self::exec('IDF_Scm_Git::appendBlobInfoCache',
                       sprintf($cmd, $skip), $rawlog);
        }
        $this->cacheBlobInfo($res);
        return $res;
    }

    /**
     * Build the blob info cache.
     *
     * We build the blob info cache 500 commits at a time.
     */
    public function buildBlobInfoCache()
    {
        $rawlog = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log --raw --abbrev=40 --pretty=oneline -500 --skip=%%s',
                     escapeshellarg($this->repo));
        $skip = 0;
        self::exec('IDF_Scm_Git::buildBlobInfoCache',
                   sprintf($cmd, $skip), $rawlog);
        while (count($rawlog)) {
            $commit = '';
            $data = array();
            foreach ($rawlog as $line) {
                if (substr($line, 0, 1) != ':') {
                    $commit = $this->getCommit(substr($line, 0, 40));
                    continue;
                }
                $blob = substr($line, 56, 40);
                $data[] = (object) array('hash' => $blob,
                                         'date' => $commit->date,
                                         'title' => $commit->title,
                                         'author' => $commit->author);
            }
            $this->cacheBlobInfo($data);
            $rawlog = array();
            $skip += 500;
            self::exec('IDF_Scm_Git::buildBlobInfoCache',
                       sprintf($cmd, $skip), $rawlog);
        }
    }

    /**
     * Get blob info.
     *
     * When we display the tree, we want to know when a given file was
     * created, who was the author and at which date. This is a very
     * slow operation for git as we need to go through the full
     * history, find when then blob was introduced, then grab the
     * corresponding commit. This is why we need a cache.
     *
     * @param array List as keys of blob hashs to get info for
     * @return array Hash indexed results, when not found not set
     */
    public function getCachedBlobInfo($hashes)
    {
        $cache = new IDF_Scm_Cache_Git();
        $cache->_project = $this->project;
        return $cache->retrieve(array_keys($hashes));
    }

    /**
     * Cache blob info.
     *
     * Given a series of blob info, cache them.
     *
     * @param array Blob info
     * @return bool Success
     */
    public function cacheBlobInfo($info)
    {
        $cache = new IDF_Scm_Cache_Git();
        $cache->_project = $this->project;
        return $cache->store($info);
    }

    public function getFileCachedBlobInfo($hashes)
    {
        $res = array();
        $cache = Pluf::f('tmp_folder').'/IDF_Scm_Git-'.md5($this->repo).'.cache.db';
        if (!file_exists($cache)) {
            return $res;
        }
        $data = file_get_contents($cache);
        if (false === $data) {
            return $res;
        }
        $data = explode(chr(30), $data);
        foreach ($data as $rec) {
            if (isset($hashes[substr($rec, 0, 40)])) {
                $tmp = explode(chr(31), substr($rec, 40), 3);
                $res[substr($rec, 0, 40)] =
                    (object) array('hash' => substr($rec, 0, 40),
                                   'date' => $tmp[0],
                                   'title' => $tmp[2],
                                   'author' => $tmp[1]);
            }
        }
        return $res;
    }

    /**
     * File cache blob info.
     *
     * Given a series of blob info, cache them.
     *
     * @param array Blob info
     * @return bool Success
     */
    public function fileCacheBlobInfo($info)
    {
        // Prepare the data
        $data = array();
        foreach ($info as $file) {
            $data[] = $file->hash.$file->date.chr(31).$file->author.chr(31).$file->title;
        }
        $data = implode(chr(30), $data).chr(30);
        $cache = Pluf::f('tmp_folder').'/IDF_Scm_Git-'.md5($this->repo).'.cache.db';
        $fp = fopen($cache, 'ab');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $data, strlen($data));
            fclose($fp); // releases the lock too
            return true;
        }
        return false;
    }
}
