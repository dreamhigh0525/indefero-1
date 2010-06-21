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
n# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
#
# ***** END LICENSE BLOCK ***** */

Pluf::loadFunction('Pluf_HTTP_URL_urlForView');
Pluf::loadFunction('Pluf_Template_dateAgo');

/**
 * Base definition of a commit.
 *
 * By having a reference in the database for each commit, one can
 * easily generate a timeline or use the search engine. Commit details
 * are normally always taken from the underlining SCM.
 */
class IDF_Commit extends Pluf_Model
{
    public $_model = __CLASS__;

    function init()
    {
        $this->_a['table'] = 'idf_commits';
        $this->_a['model'] = __CLASS__;
        $this->_a['cols'] = array(
                             // It is mandatory to have an "id" column.
                            'id' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Sequence',
                                  'blank' => true, 
                                  ),
                            'project' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'IDF_Project',
                                  'blank' => false,
                                  'verbose' => __('project'),
                                  'relate_name' => 'commits',
                                  ),
                            'author' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'Pluf_User',
                                  'is_null' => true,
                                  'verbose' => __('submitter'),
                                  'relate_name' => 'submitted_commit',
                                  'help_text' => 'This will allow us to list the latest commits of a user in its profile.',
                                  ),
                            'origauthor' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Varchar',
                                  'blank' => false,
                                  'size' => 150,
                                  'help_text' => 'As we do not necessary have the mapping between the author in the database and the scm, we store the scm author commit information here. That way we can update the author info later in the process.',
                                  ),
                            'scm_id' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Varchar',
                                  'blank' => false,
                                  'size' => 50,
                                  'index' => true,
                                  'help_text' => 'The id of the commit. For git, it will be the SHA1 hash, for subversion it will be the revision id.',
                                  ),
                            'summary' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Varchar',
                                  'blank' => false,
                                  'size' => 250,
                                  'verbose' => __('summary'),
                                  ),
                            'fullmessage' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Text',
                                  'blank' => true,
                                  'verbose' => __('changelog'),
                                  'help_text' => 'This is the full message of the commit.',
                                  ),
                            'creation_dtime' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Datetime',
                                  'blank' => true,
                                  'verbose' => __('creation date'),
                                  'index' => true,
                                  'help_text' => 'Date of creation by the scm',
                                  ),
                            );
    }

    function __toString()
    {
        return $this->summary.' - ('.$this->scm_id.')';
    }

    function _toIndex()
    {
        $str = str_repeat($this->summary.' ', 4).' '.$this->fullmessage;
        return Pluf_Text::cleanString(html_entity_decode($str, ENT_QUOTES, 'UTF-8'));
    }

    function postSave($create=false)
    {
        IDF_Search::index($this);
        if ($create) {
            IDF_Timeline::insert($this, $this->get_project(), 
                                 $this->get_author(), $this->creation_dtime);
        }
    }

    function preDelete()
    {
        IDF_Timeline::remove($this);
        IDF_Search::remove($this);
    }

    /**
     * Create a commit from a simple class commit info of a changelog.
     *
     * @param stdClass Commit info
     * @param IDF_Project Current project
     * @return IDF_Commit
     */
    public static function getOrAdd($change, $project)
    {
        $sql = new Pluf_SQL('project=%s AND scm_id=%s',
                            array($project->id, $change->commit));
        $r = Pluf::factory('IDF_Commit')->getList(array('filter'=>$sql->gen()));
        if ($r->count() > 0) {
            return $r[0];
        }
        if (!isset($change->full_message)) {
            $change->full_message = '';
        }
        $scm = IDF_Scm::get($project);
        $commit = new IDF_Commit();
        $commit->project = $project;
        $commit->scm_id = $change->commit;
        $commit->summary = self::toUTF8($change->title);
        $commit->fullmessage = self::toUTF8($change->full_message);
        $commit->author = $scm->findAuthor($change->author);
        $commit->origauthor = $change->author;
        $commit->creation_dtime = $change->date;
        $commit->create();
        $commit->notify($project->getConf());
        return $commit;
    }

    /**
     * Convert encoding to UTF8.
     *
     * If an array is given, the encoding is detected only on the
     * first value and then used to convert all the strings.
     *
     * @param mixed String or array of string to be converted
     * @param bool Returns the encoding together with the converted text (false)
     * @return mixed String or array of string or array of res + encoding
     */
    public static function toUTF8($text, $get_encoding=False)
    {
        $enc = 'ASCII, UTF-8, ISO-8859-1, JIS, EUC-JP, SJIS';
        $ref = $text;
        if (is_array($text)) {
            $ref = $text[0];
        }
        if (Pluf_Text_UTF8::check($ref)) {
            return (!$get_encoding) ? $text : array($text, 'UTF-8');
        }
        $encoding = mb_detect_encoding($ref, $enc, true);
        if ($encoding == false) {
            $encoding = Pluf_Text_UTF8::detect_cyr_charset($ref);
        }
        if (is_array($text)) {
            foreach ($text as $t) {
                $res[] = mb_convert_encoding($t, 'UTF-8', $encoding);
            }
            return (!$get_encoding) ? $res : array($res, $encoding);
        } else {
            $res = mb_convert_encoding($text, 'UTF-8', $encoding);
            return (!$get_encoding) ? $res : array($res, $encoding);
        }
    }

    /**
     * Returns the timeline fragment for the commit.
     *
     *
     * @param Pluf_HTTP_Request 
     * @return Pluf_Template_SafeString
     */
    public function timelineFragment($request)
    {
        $url = Pluf_HTTP_URL_urlForView('IDF_Views_Source::commit', 
                                        array($request->project->shortname, 
                                              $this->scm_id));
        $out = '<tr class="log"><td><a href="'.$url.'">'.
            Pluf_esc(Pluf_Template_dateAgo($this->creation_dtime, 'without')).
            '</a></td><td>';
        $stag = new IDF_Template_ShowUser();
        $user = $stag->start($this->get_author(), $request, $this->origauthor, false);
        $tag = new IDF_Template_IssueComment();
        $out .= $tag->start($this->summary, $request, false);
        if (0 && $this->fullmessage) {
            $out .= '<br /><br />'.$tag->start($this->fullmessage, $request, false);
        }
        $out .= '</td>
</tr>
<tr class="extra">
<td colspan="2">
<div class="helptext right">'.sprintf(__('Commit&nbsp;%s, by %s'), '<a href="'.$url.'" class="mono">'.$this->scm_id.'</a>', $user).'</div></td></tr>'; 
        return Pluf_Template::markSafe($out);
    }

    /**
     * Returns the feed fragment for the commit.
     *
     * @param Pluf_HTTP_Request 
     * @return Pluf_Template_SafeString
     */
    public function feedFragment($request)
    {
        $url = Pluf::f('url_base')
            .Pluf_HTTP_URL_urlForView('IDF_Views_Source::commit', 
                                      array($request->project->shortname, 
                                            $this->scm_id));
        $date = Pluf_Date::gmDateToGmString($this->creation_dtime);
        $author = ($this->get_author()) ? 
            $this->get_author() : $this->origauthor;
        $cproject = $this->get_project();
        $context = new Pluf_Template_Context_Request(
                       $request,
                       array(
                             'c' => $this,
                             'cproject' => $cproject,
                             'url' => $url,
                             'date' => $date,
                             'author' => $author,
                             )
                                             );
        $tmpl = new Pluf_Template('idf/source/feedfragment.xml');
        return $tmpl->render($context);
    }

    /**
     * Notification of change of the object.
     *
     * @param IDF_Conf Current configuration
     * @param bool Creation (true)
     */
    public function notify($conf, $create=true)
    {
        // Now we add to the queue, soon we will push everything in
        // the queue, including email notifications and indexing.
        // Even if the url is empty, we add to the queue as some
        // plugins may want to do something with this information in
        // an asynchronous way.
        $project = $this->get_project();
        $scm = $project->getConf()->getVal('scm', 'git');
        $url = str_replace(array('%p', '%r'),
                           array($project->shortname, $this->scm_id),
                           $conf->getVal('webhook_url', ''));
        $payload = array('to_send' => array(
                                            'project' => $project->shortname,
                                            'rev' => $this->scm_id,
                                            'scm' => $scm,
                                            'summary' => $this->summary,
                                            'fullmessage' => $this->fullmessage,
                                            'author' => $this->origauthor,
                                            'creation_date' => $this->creation_dtime,
                                            ),
                         'project_id' => $project->id,
                         'authkey' => $project->getPostCommitHookKey(),
                         'url' => $url,
                         );
        $item = new IDF_Queue();
        $item->type = 'new_commit';
        $item->payload = $payload;
        $item->create();

        if ('' == $conf->getVal('source_notification_email', '')) {
            return;
        }

        $current_locale = Pluf_Translation::getLocale();
        $langs = Pluf::f('languages', array('en'));
        Pluf_Translation::loadSetLocale($langs[0]);        

        $context = new Pluf_Template_Context(
                       array(
                             'c' => $this,
                             'project' => $this->get_project(),
                             'url_base' => Pluf::f('url_base'),
                             )
                                             );
        $tmpl = new Pluf_Template('idf/source/commit-created-email.txt');
        $text_email = $tmpl->render($context);
        $email = new Pluf_Mail(Pluf::f('from_email'), 
                               $conf->getVal('source_notification_email'),
                               sprintf(__('New Commit %s - %s (%s)'),
                                       $this->scm_id, $this->summary, 
                                       $this->get_project()->shortname));
        $email->addTextMessage($text_email);
        $email->sendMail();
        Pluf_Translation::loadSetLocale($current_locale);
    }
}
