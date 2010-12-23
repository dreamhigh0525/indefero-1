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
 * A patch to be reviewed.
 *
 * A patch can be marked as being directly the commit, in that case
 * the patch does not store the diff file as it can be retrieved from
 * the backend.
 *
 */
class IDF_Review_Patch extends Pluf_Model
{
    public $_model = __CLASS__;

    function init()
    {
        $this->_a['table'] = 'idf_review_patches';
        $this->_a['model'] = __CLASS__;
        $this->_a['cols'] = array(
                             // It is mandatory to have an "id" column.
                            'id' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Sequence',
                                  'blank' => true, 
                                  ),
                            'review' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'IDF_Review',
                                  'blank' => false,
                                  'verbose' => __('review'),
                                  'relate_name' => 'patches',
                                  ),
                            'summary' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Varchar',
                                  'blank' => false,
                                  'size' => 250,
                                  'verbose' => __('summary'),
                                  ),
                            'commit' => 
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'IDF_Commit',
                                  'blank' => false,
                                  'verbose' => __('commit'),
                                  'relate_name' => 'patches',
                                  ),
                            'description' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Text',
                                  'blank' => false,
                                  'verbose' => __('description'),
                                  ),
                            'patch' =>
                            array(
                                  'type' => 'Pluf_DB_Field_File',
                                  'blank' => false,
                                  'verbose' => __('patch'),
                                  'help_text' => 'The patch is stored at the same place as the issue attachments with the same approach for the name.',
                                  ),
                            'creation_dtime' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Datetime',
                                  'blank' => true,
                                  'verbose' => __('creation date'),
                                  'index' => true,
                                  ),
                            );
    }

    /**
     * Get the list of file comments.
     *
     * It will go through the patch comments and find for each the
     * file comments.
     *
     * @param array Filter to apply to the file comment list (array())
     */
    function getFileComments($filter=array())
    {
        $files = new ArrayObject();
        foreach ($this->get_comments_list(array('order'=>'creation_dtime ASC')) as $ct) {
            foreach ($ct->get_filecomments_list($filter) as $fc) {
                $files[] = $fc;
            }
        }
        return $files;
    }

    function _toIndex()
    {
        return '';
    }

    function preDelete()
    {
        IDF_Timeline::remove($this);
    }

    function preSave($create=false)
    {
        if ($create) {
            $this->creation_dtime = gmdate('Y-m-d H:i:s');
        }
    }

    function postSave($create=false)
    {
        if ($create) {
            IDF_Timeline::insert($this, 
                                 $this->get_review()->get_project(), 
                                 $this->get_review()->get_submitter());
            IDF_Search::index($this->get_review());
        }
    }

    public function timelineFragment($request)
    {
        $review = $this->get_review();
        $url = Pluf_HTTP_URL_urlForView('IDF_Views_Review::view', 
                                        array($request->project->shortname,
                                              $review->id));
        $out = '<tr class="log"><td><a href="'.$url.'">'.
            Pluf_esc(Pluf_Template_dateAgo($this->creation_dtime, 'without')).
            '</a></td><td>';
        $stag = new IDF_Template_ShowUser();
        $user = $stag->start($review->get_submitter(), $request, '', false);
        $ic = (in_array($review->status, $request->project->getTagIdsByStatus('closed'))) ? 'issue-c' : 'issue-o';
        $out .= sprintf(__('<a href="%1$s" class="%2$s" title="View review">Review %3$d</a>, %4$s'), $url, $ic, $review->id, Pluf_esc($review->summary)).'</td>';
        $out .= "\n".'<tr class="extra"><td colspan="2">
<div class="helptext right">'.sprintf(__('Creation of <a href="%s" class="%s">review&nbsp;%d</a>, by %s'), $url, $ic, $review->id, $user).'</div></td></tr>'; 
        return Pluf_Template::markSafe($out);
    }

    public function feedFragment($request)
    {
        $review = $this->get_review();
        $url = Pluf_HTTP_URL_urlForView('IDF_Views_Review::view', 
                                        array($request->project->shortname,
                                              $review->id));
        $title = sprintf(__('%s: Creation of Review %d - %s'),
                         Pluf_esc($request->project->name),
                         $review->id, Pluf_esc($review->summary));
        $date = Pluf_Date::gmDateToGmString($this->creation_dtime);
        $context = new Pluf_Template_Context_Request(
                       $request,
                       array('url' => $url,
                             'author' => $review->get_submitter(),
                             'title' => $title,
                             'p' => $this,
                             'review' => $review,
                             'date' => $date)
                                                     );
        $tmpl = new Pluf_Template('idf/review/feedfragment.xml');
        return $tmpl->render($context);
    }

    public function notify($conf, $create=true)
    {
        if ('' == $conf->getVal('review_notification_email', '')) {
            return;
        }
        $current_locale = Pluf_Translation::getLocale();
        $langs = Pluf::f('languages', array('en'));
        Pluf_Translation::loadSetLocale($langs[0]);        

        $context = new Pluf_Template_Context(
                       array(
                             'review' => $this->get_review(),
                             'patch' => $this,
                             'comments' => array(),
                             'project' => $this->get_review()->get_project(),
                             'url_base' => Pluf::f('url_base'),
                             )
                                                     );
        $tmpl = new Pluf_Template('idf/review/review-created-email.txt');
        $text_email = $tmpl->render($context);
        $addresses = explode(';',$conf->getVal('review_notification_email'));
        foreach ($addresses as $address) {
            $email = new Pluf_Mail(Pluf::f('from_email'), 
                                   $address,
                                   sprintf(__('New Code Review %s - %s (%s)'),
                                           $this->get_review()->id, 
                                           $this->get_review()->summary, 
                                           $this->get_review()->get_project()->shortname));
            $email->addTextMessage($text_email);
            $email->sendMail();
        }
        Pluf_Translation::loadSetLocale($current_locale);
    }
}
