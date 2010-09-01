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

Pluf::loadFunction('Pluf_HTTP_URL_urlForView');
Pluf::loadFunction('Pluf_Shortcuts_RenderToResponse');
Pluf::loadFunction('Pluf_Shortcuts_GetObjectOr404');
Pluf::loadFunction('Pluf_Shortcuts_GetFormForModel');

/**
 * View SCM repository.
 */
class IDF_Views_Source
{
    /**
     * Extension supported by the syntax highlighter.
     */
    public static $supportedExtenstions = array(
              'ascx', 'ashx', 'asmx', 'aspx', 'browser', 'bsh', 'c', 'cc',
              'config', 'cpp', 'cs', 'csh',	'csproj', 'css', 'cv', 'cyc',
              'html', 'html', 'java', 'js', 'master', 'perl', 'php', 'pl',
              'pm', 'py', 'rb', 'sh', 'sitemap', 'skin', 'sln', 'svc', 'vala',
              'vb', 'vbproj', 'wsdl', 'xhtml', 'xml', 'xsd', 'xsl', 'xslt');

    /**
     * Display help on how to checkout etc.
     */
    public $help_precond = array('IDF_Precondition::accessSource');
    public function help($request, $match)
    {
        $title = sprintf(__('%s Source Help'), (string) $request->project);
        $scm = IDF_Scm::get($request->project);
        $scmConf = $request->conf->getVal('scm', 'git');
        $params = array(
                        'page_title' => $title,
                        'title' => $title,
                        'scm' => $scmConf,
                        );
        return Pluf_Shortcuts_RenderToResponse('idf/source/'.$scmConf.'/help.html',
                                               $params, $request);
    }

    /**
     * Is displayed in case an invalid revision is requested
     */
    public $invalidRevision_precond = array('IDF_Precondition::accessSource');
    public function invalidRevision($request, $match)
    {
        $title = sprintf(__('%s Invalid Revision'), (string) $request->project);
        $commit = $match[2];
        $params = array(
                        'page_title' => $title,
                        'title' => $title,
                        'commit' => $commit,
                        );
        return Pluf_Shortcuts_RenderToResponse('idf/source/invalid_revision.html',
                                               $params, $request);
    }

    /**
     * Is displayed in case a revision identifier cannot be uniquely resolved
     * to one single revision
     */
    public $disambiguateRevision_precond = array('IDF_Precondition::accessSource',
                                                 'IDF_Views_Source_Precondition::scmAvailable');
    public function disambiguateRevision($request, $match)
    {
        $title = sprintf(__('%s Ambiguous Revision'), (string) $request->project);
        $commit = $match[2];
        $redirect = $match[3];
        $scm = IDF_Scm::get($request->project);
        $revisions = $scm->disambiguateRevision($commit);
        $params = array(
                        'page_title' => $title,
                        'title' => $title,
                        'commit' => $commit,
                        'revisions' => $revisions,
                        'redirect' => $redirect,
                        );
        return Pluf_Shortcuts_RenderToResponse('idf/source/disambiguate_revision.html',
                                               $params, $request);
    }

    public $changeLog_precond = array('IDF_Precondition::accessSource',
                                      'IDF_Views_Source_Precondition::scmAvailable',
                                      'IDF_Views_Source_Precondition::revisionValid');
    public function changeLog($request, $match)
    {
        $scm = IDF_Scm::get($request->project);
        $branches = $scm->getBranches();
        $commit = $match[2];

        $title = sprintf(__('%1$s %2$s Change Log'), (string) $request->project,
                         $this->getScmType($request));
        $changes = $scm->getChangeLog($commit, 25);
        $rchanges = array();
        // Sync with the database
        foreach ($changes as $change) {
            $rchanges[] = IDF_Commit::getOrAdd($change, $request->project);
        }
        $rchanges = new Pluf_Template_ContextVars($rchanges);
        $scmConf = $request->conf->getVal('scm', 'git');
        $in_branches = $scm->inBranches($commit, '');
        $tags = $scm->getTags();
        $in_tags = $scm->inTags($commit, '');
        return Pluf_Shortcuts_RenderToResponse('idf/source/'.$scmConf.'/changelog.html',
                                               array(
                                                     'page_title' => $title,
                                                     'title' => $title,
                                                     'changes' => $rchanges,
                                                     'commit' => $commit,
                                                     'branches' => $branches,
                                                     'tree_in' => $in_branches,
                                                     'tags' => $tags,
                                                     'tags_in' => $in_tags,
                                                     'scm' => $scmConf,
                                                     ),
                                               $request);
    }

    public $treeBase_precond = array('IDF_Precondition::accessSource',
                                     'IDF_Views_Source_Precondition::scmAvailable',
                                     'IDF_Views_Source_Precondition::revisionValid');
    public function treeBase($request, $match)
    {
        $scm = IDF_Scm::get($request->project);
        $commit = $match[2];

        $cobject = $scm->getCommit($commit);
        if (!$cobject) {
            throw new Exception('could not retrieve commit object for '. $commit);
        }
        $title = sprintf(__('%1$s %2$s Source Tree'),
                         $request->project, $this->getScmType($request));
        $branches = $scm->getBranches();
        $in_branches = $scm->inBranches($commit, '');
        $tags = $scm->getTags();
        $in_tags = $scm->inTags($commit, '');
        $cache = Pluf_Cache::factory();
        $key = sprintf('Project:%s::IDF_Views_Source::treeBase:%s::',
                       $request->project->id, $commit);
        if (null === ($res=$cache->get($key))) {
            $res = new Pluf_Template_ContextVars($scm->getTree($commit));
            $cache->set($key, $res);
        }
        $scmConf = $request->conf->getVal('scm', 'git');
        $props = $scm->getProperties($commit);
        return Pluf_Shortcuts_RenderToResponse('idf/source/'.$scmConf.'/tree.html',
                                               array(
                                                     'page_title' => $title,
                                                     'title' => $title,
                                                     'files' => $res,
                                                     'cobject' => $cobject,
                                                     'commit' => $commit,
                                                     'tree_in' => $in_branches,
                                                     'branches' => $branches,
                                                     'tags' => $tags,
                                                     'tags_in' => $in_tags,
                                                     'props' => $props,
                                                     ),
                                               $request);
    }

    public $tree_precond = array('IDF_Precondition::accessSource',
                                 'IDF_Views_Source_Precondition::scmAvailable',
                                 'IDF_Views_Source_Precondition::revisionValid');
    public function tree($request, $match)
    {
        $scm = IDF_Scm::get($request->project);
        $commit = $match[2];

        $request_file = $match[3];
        if (substr($request_file, -1) == '/') {
            $request_file = substr($request_file, 0, -1);
            $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::tree',
                                            array($match[1], $match[2],
                                                  $request_file));
            return new Pluf_HTTP_Response_Redirect($url, 301);
        }

        $request_file_info = $scm->getPathInfo($request_file, $commit);
        if (!$request_file_info) {
            // Redirect to the main branch
            $fburl = Pluf_HTTP_URL_urlForView('IDF_Views_Source::treeBase',
                                      array($request->project->shortname,
                                            $scm->getMainBranch()));
            return new Pluf_HTTP_Response_Redirect($fburl);
        }
        $branches = $scm->getBranches();
        $tags = $scm->getTags();
        if ($request_file_info->type != 'tree') {
            $info = self::getRequestedFileMimeType($request_file_info,
                                                   $commit, $scm);
            if (!self::isText($info)) {
                $rep = new Pluf_HTTP_Response($scm->getFile($request_file_info),
                                              $info[0]);
                $rep->headers['Content-Disposition'] = 'attachment; filename="'.$info[1].'"';
                return $rep;
            } else {
                // We want to display the content of the file as text
                $extra = array('branches' => $branches,
                               'tags' => $tags,
                               'commit' => $commit,
                               'request_file' => $request_file,
                               'request_file_info' => $request_file_info,
                               'mime' => $info,
                               );
                return $this->viewFile($request, $match, $extra);
            }
        }

        $bc = self::makeBreadCrumb($request->project, $commit, $request_file_info->fullpath);
        $title = sprintf(__('%1$s %2$s Source Tree'),
                         $request->project, $this->getScmType($request));

        $page_title = $bc.' - '.$title;
        $cobject = $scm->getCommit($commit);
        if (!$cobject) {
            // Redirect to the first branch
            $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::treeBase',
                                            array($request->project->shortname,
                                                  $scm->getMainBranch()));
            return new Pluf_HTTP_Response_Redirect($url);
        }
        $in_branches = $scm->inBranches($commit, $request_file);
        $in_tags = $scm->inTags($commit, $request_file);
        $cache = Pluf_Cache::factory();
        $key = sprintf('Project:%s::IDF_Views_Source::tree:%s::%s',
                       $request->project->id, $commit, $request_file);
        if (null === ($res=$cache->get($key))) {
            $res = new Pluf_Template_ContextVars($scm->getTree($commit, $request_file));
            $cache->set($key, $res);
        }
        // try to find the previous level if it exists.
        $prev = explode('/', $request_file);
        $l = array_pop($prev);
        $previous = substr($request_file, 0, -strlen($l.' '));
        $scmConf = $request->conf->getVal('scm', 'git');
        $props = $scm->getProperties($commit, $request_file);
        return Pluf_Shortcuts_RenderToResponse('idf/source/'.$scmConf.'/tree.html',
                                               array(
                                                     'page_title' => $page_title,
                                                     'title' => $title,
                                                     'breadcrumb' => $bc,
                                                     'files' => $res,
                                                     'commit' => $commit,
                                                     'cobject' => $cobject,
                                                     'base' => $request_file_info->file,
                                                     'prev' => $previous,
                                                     'tree_in' => $in_branches,
                                                     'branches' => $branches,
                                                     'tags' => $tags,
                                                     'tags_in' => $in_tags,
                                                     'props' => $props,
                                                     ),
                                               $request);
    }

    public static function makeBreadCrumb($project, $commit, $file, $sep='/')
    {
        $elts = explode('/', $file);
        $out = array();
        $stack = '';
        $i = 0;
        foreach ($elts as $elt) {
            $stack .= ($i==0) ? rawurlencode($elt) : '/'.rawurlencode($elt);
            $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::tree',
                                            array($project->shortname,
                                                  $commit, $stack));
            $out[] = '<a href="'.$url.'">'.Pluf_esc($elt).'</a>';
            $i++;
        }
        return '<span class="breadcrumb">'.implode('<span class="sep">'.$sep.'</span>', $out).'</span>';
    }

    public $commit_precond = array('IDF_Precondition::accessSource',
                                   'IDF_Views_Source_Precondition::scmAvailable',
                                   'IDF_Views_Source_Precondition::revisionValid');
    public function commit($request, $match)
    {
        $scm = IDF_Scm::get($request->project);
        $commit = $match[2];
        $large = $scm->isCommitLarge($commit);
        $cobject = $scm->getCommit($commit, !$large);
        if (!$cobject) {
            throw new Exception('could not retrieve commit object for '. $commit);
        }
        $title = sprintf(__('%s Commit Details'), (string) $request->project);
        $page_title = sprintf(__('%s Commit Details - %s'), (string) $request->project, $commit);
        $rcommit = IDF_Commit::getOrAdd($cobject, $request->project);
        $diff = new IDF_Diff($cobject->changes);
        $diff->parse();
        $scmConf = $request->conf->getVal('scm', 'git');
        $branches = $scm->getBranches();
        $in_branches = $scm->inBranches($cobject->commit, '');
        $tags = $scm->getTags();
        $in_tags = $scm->inTags($cobject->commit, '');
        return Pluf_Shortcuts_RenderToResponse('idf/source/'.$scmConf.'/commit.html',
                                               array(
                                                     'page_title' => $page_title,
                                                     'title' => $title,
                                                     'diff' => $diff,
                                                     'cobject' => $cobject,
                                                     'commit' => $commit,
                                                     'branches' => $branches,
                                                     'tree_in' => $in_branches,
                                                     'tags' => $tags,
                                                     'tags_in' => $in_tags,
                                                     'scm' => $scmConf,
                                                     'rcommit' => $rcommit,
                                                     'large_commit' => $large,
                                                     ),
                                               $request);
    }

    public $downloadDiff_precond = array('IDF_Precondition::accessSource',
                                         'IDF_Views_Source_Precondition::scmAvailable',
                                         'IDF_Views_Source_Precondition::revisionValid');
    public function downloadDiff($request, $match)
    {
        $scm = IDF_Scm::get($request->project);
        $commit = $match[2];
        $cobject = $scm->getCommit($commit, true);
        if (!$cobject) {
            throw new Exception('could not retrieve commit object for '. $commit);
        }
        $rep = new Pluf_HTTP_Response($cobject->changes, 'text/plain');
        $rep->headers['Content-Disposition'] = 'attachment; filename="'.$commit.'.diff"';
        return $rep;
    }

    /**
     * Should only be called through self::tree
     */
    public function viewFile($request, $match, $extra)
    {
        $title = sprintf(__('%1$s %2$s Source Tree'), (string) $request->project,
                         $this->getScmType($request));
        $scm = IDF_Scm::get($request->project);
        $branches = $extra['branches'];
        $tags = $extra['tags'];
        $commit = $extra['commit'];
        $request_file = $extra['request_file'];
        $request_file_info = $extra['request_file_info'];
        $bc = self::makeBreadCrumb($request->project, $commit, $request_file_info->fullpath);
        $page_title = $bc.' - '.$title;
        $cobject = $scm->getCommit($commit);
        $in_branches = $scm->inBranches($commit, $request_file);
        $in_tags = $scm->inTags($commit, '');
        // try to find the previous level if it exists.
        $prev = explode('/', $request_file);
        $l = array_pop($prev);
        $previous = substr($request_file, 0, -strlen($l.' '));
        $scmConf = $request->conf->getVal('scm', 'git');
        $props = $scm->getProperties($commit, $request_file);
        $content = self::highLight($extra['mime'], $scm->getFile($request_file_info));
        return Pluf_Shortcuts_RenderToResponse('idf/source/'.$scmConf.'/file.html',
                                               array(
                                                     'page_title' => $page_title,
                                                     'title' => $title,
                                                     'breadcrumb' => $bc,
                                                     'file' => $content,
                                                     'commit' => $commit,
                                                     'cobject' => $cobject,
                                                     'fullpath' => $request_file,
                                                     'efullpath' => IDF_Scm::smartEncode($request_file),
                                                     'base' => $request_file_info->file,
                                                     'prev' => $previous,
                                                     'tree_in' => $in_branches,
                                                     'branches' => $branches,
                                                     'tags' => $tags,
                                                     'tags_in' => $in_tags,
                                                     'props' => $props,
                                                     ),
                                               $request);
    }

    /**
     * Get a given file at a given commit.
     *
     */
    public $getFile_precond = array('IDF_Precondition::accessSource',
                                    'IDF_Views_Source_Precondition::scmAvailable',
                                    'IDF_Views_Source_Precondition::revisionValid');
    public function getFile($request, $match)
    {
        $scm = IDF_Scm::get($request->project);
        $commit = $match[2];
        $request_file = $match[3];
        $request_file_info = $scm->getPathInfo($request_file, $commit);
        if (!$request_file_info or $request_file_info->type == 'tree') {
            // Redirect to the first branch
            $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::treeBase',
                                            array($request->project->shortname,
                                                  $scm->getMainBranch()));
            return new Pluf_HTTP_Response_Redirect($url);
        }
        $info = self::getRequestedFileMimeType($request_file_info,
                                                   $commit, $scm);
        $rep = new Pluf_HTTP_Response($scm->getFile($request_file_info),
                                      $info[0]);
        $rep->headers['Content-Disposition'] = 'attachment; filename="'.$info[1].'"';
        return $rep;
    }

    /**
     * Get a zip archive of the current commit.
     *
     */
    public $download_precond = array('IDF_Precondition::accessSource',
                                     'IDF_Views_Source_Precondition::scmAvailable',
                                     'IDF_Views_Source_Precondition::revisionValid');
    public function download($request, $match)
    {
        $commit = trim($match[2]);
        $scm = IDF_Scm::get($request->project);
        $base = $request->project->shortname.'-'.$commit;
        $cmd = $scm->getArchiveCommand($commit, $base.'/');
        $rep = new Pluf_HTTP_Response_CommandPassThru($cmd, 'application/x-zip');
        $rep->headers['Content-Transfer-Encoding'] = 'binary';
        $rep->headers['Content-Disposition'] = 'attachment; filename="'.$base.'.zip"';
        return $rep;
    }

    /**
     * Find the mime type of a requested file.
     *
     * @param stdClass Request file info
     * @param string Commit at which we want the file
     * @param IDF_Scm SCM object
     * @param array  Mime type found or 'application/octet-stream', basename, extension
     */
    public static function getRequestedFileMimeType($file_info, $commit, $scm)
    {
        $mime = self::getMimeType($file_info->file);
        if ('application/octet-stream' != $mime[0]) {
            return $mime;
        }
        return self::getMimeTypeFromContent($file_info->file,
                                            $scm->getFile($file_info));
    }

     /**
      * Find the mime type of a file using the fileinfo class.
      *
      * @param string Filename/Filepath
      * @param string File content
      * @return array Mime type found or 'application/octet-stream', basename, extension
      */
    public static function getMimeTypeFromContent($file, $filedata)
    {
        $info = pathinfo($file);
        $res = array('application/octet-stream',
                     $info['basename'],
                     isset($info['extension']) ? $info['extension'] : 'bin');
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME);
            $mime = finfo_buffer($finfo, $filedata);
            finfo_close($finfo);
            if ($mime) {
                $res[0] = $mime;
            }
            if (!isset($info['extension']) && $mime) {
                $res[2] = (0 === strpos($mime, 'text/')) ? 'txt' : 'bin';
            } elseif (!isset($info['extension'])) {
                $res[2] = 'bin';
            }
        }
        return $res;
    }

    /**
     * Find the mime type of a file.
     *
     * Use /etc/mime.types to find the type.
     *
     * @param string Filename/Filepath
     * @param array  Mime type found or 'application/octet-stream', basename, extension
     */
    public static function getMimeType($file)
    {
        $src= Pluf::f('idf_mimetypes_db', '/etc/mime.types');
        $mimes = preg_split("/\015\012|\015|\012/", file_get_contents($src));
        $info = pathinfo($file);
        if (isset($info['extension'])) {
            foreach ($mimes as $mime) {
                if ('#' != substr($mime, 0, 1)) {
                    $elts = preg_split('/ |\t/', $mime, -1, PREG_SPLIT_NO_EMPTY);
                    if (in_array($info['extension'], $elts)) {
                        return array($elts[0], $info['basename'], $info['extension']);
                    }
                }
            }
        } else {
            // we consider that if no extension and base name is all
            // uppercase, then we have a text file.
            if ($info['basename'] == strtoupper($info['basename'])) {
                return array('text/plain', $info['basename'], 'txt');
            }
            $info['extension'] = 'bin';
        }
        return array('application/octet-stream', $info['basename'], $info['extension']);
    }

    /**
     * Find if a given mime type is a text file.
     * This uses the output of the self::getMimeType function.
     *
     * @param array (Mime type, file name, extension)
     * @return bool Is text
     */
    public static function isText($fileinfo)
    {
        if (0 === strpos($fileinfo[0], 'text/')) {
            return true;
        }
        $ext = 'mdtext php-dist h gitignore diff patch'
            .Pluf::f('idf_extra_text_ext', '');
        $ext = array_merge(self::$supportedExtenstions, explode(' ' , $ext));
        return (in_array($fileinfo[2], $ext));
    }

    public static function highLight($fileinfo, $content)
    {
        $pretty = '';
        if (self::isSupportedExtension($fileinfo[2])) {
            $pretty = ' prettyprint';
        }
        $table = array();
        $i = 1;
        foreach (preg_split("/\015\012|\015|\012/", $content) as $line) {
            $table[] = '<tr class="c-line"><td class="code-lc" id="L'.$i.'"><a href="#L'.$i.'">'.$i.'</a></td>'
                .'<td class="code mono'.$pretty.'">'.IDF_Diff::padLine(Pluf_esc($line)).'</td></tr>';
            $i++;
        }
        return Pluf_Template::markSafe(implode("\n", $table));
    }

    /**
     * Test if an extension is supported by the syntax highlighter.
     *
     * @param string The extension to test
     * @return bool
     */
    public static function isSupportedExtension($extension)
    {
        return in_array($extension, self::$supportedExtenstions);
    }

    /**
     * Get the scm type for page title
     *
     * @return String
     */
    private function getScmType($request)
    {
        return mb_convert_case($request->conf->getVal('scm', 'git'),
                               MB_CASE_TITLE, 'UTF-8');
    }
}

function IDF_Views_Source_PrettySize($size)
{
    return Pluf_Template::markSafe(str_replace(' ', '&nbsp;',
                                               Pluf_Utils::prettySize($size)));
}

function IDF_Views_Source_PrettySizeSimple($size)
{
    return Pluf_Utils::prettySize($size);
}

function IDF_Views_Source_ShortenString($string, $length)
{
    $ellipse = "...";
    $length = max(strlen($ellipse) + 2, $length);
    $preflen = ceil($length / 10);

    if (mb_strlen($string) < $length)
        return $string;

    return substr($string, 0, $preflen).$ellipse.
           substr($string, -($length - $preflen - mb_strlen($ellipse)));
}

class IDF_Views_Source_Precondition
{
    /**
     * Ensures that the configured SCM for the project is available
     *
     * @param $request
     * @return true | Pluf_HTTP_Response_Redirect
     */
    static public function scmAvailable($request)
    {
        $scm = IDF_Scm::get($request->project);
        if (!$scm->isAvailable()) {
            $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::help',
                                            array($request->project->shortname));
            return new Pluf_HTTP_Response_Redirect($url);
        }
        return true;
    }

    /**
     * Validates the revision given in the URL path and acts accordingly
     *
     * @param $request
     * @return true | Pluf_HTTP_Response_Redirect
     * @throws Exception
     */
    static public function revisionValid($request)
    {
        list($url_info, $url_matches) = $request->view;
        list(, $project, $commit) = $url_matches;

        $scm = IDF_Scm::get($request->project);
        $res = $scm->validateRevision($commit);
        switch ($res) {
            case IDF_Scm::REVISION_VALID:
                return true;
            case IDF_Scm::REVISION_INVALID:
                $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::invalidRevision',
                                                array($request->project->shortname, $commit));
                return new Pluf_HTTP_Response_Redirect($url);
            case IDF_Scm::REVISION_AMBIGUOUS:
                $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::disambiguateRevision',
                                                array($request->project->shortname,
                                                      $commit,
                                                      $url_info['model'].'::'.$url_info['method']));
                return new Pluf_HTTP_Response_Redirect($url);
            default:
                throw new Exception('unknown validation result: '. $res);
        }
    }
}

