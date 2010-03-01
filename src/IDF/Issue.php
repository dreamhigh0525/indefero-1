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
Pluf::loadFunction('Pluf_Template_dateAgo');

/**
 * Base definition of an issue.
 *
 * An issue can have labels, comments, can be starred by people.
 */
class IDF_Issue extends Pluf_Model
{
    public $_model = __CLASS__;

    function init()
    {
        $this->_a['table'] = 'idf_issues';
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
                                  'relate_name' => 'issues',
                                  ),
                            'summary' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Varchar',
                                  'blank' => false,
                                  'size' => 250,
                                  'verbose' => __('summary'),
                                  ),
                            'submitter' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'Pluf_User',
                                  'blank' => false,
                                  'verbose' => __('submitter'),
                                  'relate_name' => 'submitted_issue',
                                  ),
                            'owner' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'Pluf_User',
                                  'blank' => true, // no owner when submitted.
                                  'is_null' => true,
                                  'verbose' => __('owner'),
                                  'relate_name' => 'owned_issue',
                                  ),
                            'interested' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Manytomany',
                                  'model' => 'Pluf_User',
                                  'blank' => true,
                                  'verbose' => __('interested users'),
                                  'help_text' => __('Interested users will get an email notification when the issue is changed.'),
                                  ),
                            'tags' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Manytomany', 
                                  'blank' => true,
                                  'model' => 'IDF_Tag',
                                  'verbose' => __('labels'),
                                  ),
                            'status' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey', 
                                  'blank' => false,
                                  'model' => 'IDF_Tag',
                                  'verbose' => __('status'),
                                  ),
                            'creation_dtime' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Datetime',
                                  'blank' => true,
                                  'verbose' => __('creation date'),
                                  ),
                            'modif_dtime' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Datetime',
                                  'blank' => true,
                                  'verbose' => __('modification date'),
                                  ),
                            );
        $this->_a['idx'] = array(                           
                            'modif_dtime_idx' =>
                            array(
                                  'col' => 'modif_dtime',
                                  'type' => 'normal',
                                  ),
                            );
        $table = $this->_con->pfx.'idf_issue_idf_tag_assoc';
        $this->_a['views'] = array(
                              'join_tags' => 
                              array(
                                    'join' => 'LEFT JOIN '.$table
                                    .' ON idf_issue_id=id',
                                    ),
                                   );
    }

    function __toString()
    {
        return $this->id.' - '.$this->summary;
    }

    function _toIndex()
    {
        $r = array();
        foreach ($this->get_comments_list() as $c) {
            $r[] = $c->_toIndex();
        }
        $str = str_repeat($this->summary.' ', 4).' '.implode(' ', $r);
        return Pluf_Text::cleanString(html_entity_decode($str, ENT_QUOTES, 'UTF-8'));
    }

    function preDelete()
    {
        IDF_Timeline::remove($this);
        IDF_Search::remove($this);
    }

    function preSave($create=false)
    {
        if ($this->id == '') {
            $this->creation_dtime = gmdate('Y-m-d H:i:s');
        }
        $this->modif_dtime = gmdate('Y-m-d H:i:s');
    }

    function postSave($create=false)
    {
        // Note: No indexing is performed here. The indexing is
        // triggered in the postSave step of the comment to ensure
        // that the issue as at least one comment in the database when
        // doing the indexing.
        if ($create) {
            IDF_Timeline::insert($this, $this->get_project(), 
                                 $this->get_submitter());
        }
    }

    /**
     * Returns an HTML fragment used to display this issue in the
     * timeline.
     *
     * The request object is given to be able to check the rights and
     * as such create links to other items etc. You can consider that
     * if displayed, you can create a link to it.
     *
     * @param Pluf_HTTP_Request 
     * @return Pluf_Template_SafeString
     */
    public function timelineFragment($request)
    {
        $url = Pluf_HTTP_URL_urlForView('IDF_Views_Issue::view', 
                                        array($request->project->shortname,
                                              $this->id));
        $out = '<tr class="log"><td><a href="'.$url.'">'.
            Pluf_esc(Pluf_Template_dateAgo($this->creation_dtime, 'without')).
            '</a></td><td>';
        $stag = new IDF_Template_ShowUser();
        $user = $stag->start($this->get_submitter(), $request, '', false);
        $ic = (in_array($this->status, $request->project->getTagIdsByStatus('closed'))) ? 'issue-c' : 'issue-o';
        $out .= sprintf(__('<a href="%1$s" class="%2$s" title="View issue">Issue %3$d</a>, %4$s'), $url, $ic, $this->id, Pluf_esc($this->summary)).'</td>';
        $out .= "\n".'<tr class="extra"><td colspan="2">
<div class="helptext right">'.sprintf(__('Creation of <a href="%s" class="%s">issue&nbsp;%d</a>, by %s'), $url, $ic, $this->id, $user).'</div></td></tr>'; 
        return Pluf_Template::markSafe($out);
    }

    public function feedFragment($request)
    {
        $url = Pluf::f('url_base')
            .Pluf_HTTP_URL_urlForView('IDF_Views_Issue::view', 
                                      array($request->project->shortname,
                                            $this->id));
        $title = sprintf(__('%s: Issue %d created - %s'),
                         $request->project->name,
                         $this->id, $this->summary);
        $cts = $this->get_comments_list(array('order' => 'id ASC',
                                              'nb' => 1));
        $date = Pluf_Date::gmDateToGmString($this->creation_dtime);
        $context = new Pluf_Template_Context_Request(
                       $request,
                       array('url' => $url,
                             'author' => $this->get_submitter(),
                             'title' => $title,
                             'c' => $cts[0],
                             'issue' => $this,
                             'date' => $date)
                                                     );
        $tmpl = new Pluf_Template('idf/issues/feedfragment.xml');
        return $tmpl->render($context);
    }

    /**
     * Notification of change of the object.
     *
     * For the moment, only email, but one can add webhooks later.
     *
     * Usage:
     * <pre>
     * $this->notify($conf); // Notify the creation
     * $this->notify($conf, false); // Notify the update of the object
     * </pre>
     *
     * @param IDF_Conf Current configuration
     * @param bool Creation (true)
     */
    public function notify($conf, $create=true)
    {
        $prj = $this->get_project();
        $to_email = array();
        if ('' != $conf->getVal('issues_notification_email', '')) {
            $langs = Pluf::f('languages', array('en'));
            $to_email[] = array($conf->getVal('issues_notification_email'),
                                $langs[0]);
        }
        $current_locale = Pluf_Translation::getLocale();

        if ($create) {
            if (null != $this->get_owner() and $this->owner != $this->submitter) {                
                $email_lang = array($this->get_owner()->email, 
                                    $this->get_owner()->language);
                if (!in_array($email_lang, $to_email)) {
                    $to_email[] = $email_lang;
                }
            }
            $comments = $this->get_comments_list(array('order' => 'id ASC'));
            $context = new Pluf_Template_Context(
                                   array(
                                         'issue' => $this,
                                         'comment' => $comments[0],
                                         'project' => $prj,
                                         'url_base' => Pluf::f('url_base'),
                                         )
                                                 );
            foreach ($to_email as $email_lang) {
                Pluf_Translation::loadSetLocale($email_lang[1]);
                $email = new Pluf_Mail(Pluf::f('from_email'), $email_lang[0],
                                       sprintf(__('Issue %s - %s (%s)'),
                                               $this->id, $this->summary, $prj->shortname));
                $tmpl = new Pluf_Template('idf/issues/issue-created-email.txt');
                $email->addTextMessage($tmpl->render($context));
                $email->sendMail();
            }
        } else {
            $comments = $this->get_comments_list(array('order' => 'id DESC'));
            $email_sender = '';
            if (isset($comments[0])) {
                $email_sender = $comments[0]->get_submitter()->email;
            }
            foreach ($this->get_interested_list() as $interested) {
                $email_lang = array($interested->email,
                                    $interested->language);
                if (!in_array($email_lang, $to_email)) {
                    $to_email[] = $email_lang;
                }
            }
            $email_lang = array($this->get_submitter()->email,
                                $this->get_submitter()->language);
            if (!in_array($email_lang, $to_email)) {
                $to_email[] = $email_lang;
            }
            if (null != $this->get_owner()) {
                $email_lang = array($this->get_owner()->email,
                                    $this->get_owner()->language);
                if (!in_array($email_lang, $to_email)) {
                    $to_email[] = $email_lang;
                }
            }
            $context = new Pluf_Template_Context(
                            array(
                                  'issue' => $this,
                                  'comments' => $comments,
                                  'project' => $prj,
                                  'url_base' => Pluf::f('url_base'),
                                  ));
            foreach ($to_email as $email_lang) {
                if ($email_lang[0] == $email_sender) {
                    continue; // Do not notify the one having created
                              // the comment
                }
                Pluf_Translation::loadSetLocale($email_lang[1]);
                $email = new Pluf_Mail(Pluf::f('from_email'), $email_lang[0],
                                       sprintf(__('Updated Issue %s - %s (%s)'),
                                               $this->id, $this->summary, $prj->shortname));
                $tmpl = new Pluf_Template('idf/issues/issue-updated-email.txt');
                $email->addTextMessage($tmpl->render($context));
                $email->sendMail();
            }
        }
        Pluf_Translation::loadSetLocale($current_locale);
    }
}