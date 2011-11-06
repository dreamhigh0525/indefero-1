<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2008-2011 Céondo Ltd and contributors.
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
 * A single resource revision.
 */
class IDF_Wiki_ResourceRevision extends Pluf_Model
{
    public $_model = __CLASS__;

    function init()
    {
        $this->_a['table'] = 'idf_wikiresourcerevs';
        $this->_a['model'] = __CLASS__;
        $this->_a['cols'] = array(
                             // It is mandatory to have an "id" column.
                            'id' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Sequence',
                                  'blank' => true,
                                  ),
                            'wikiresource' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'IDF_Wiki_Resource',
                                  'blank' => false,
                                  'verbose' => __('resource'),
                                  'relate_name' => 'revisions',
                                  ),
                            'is_head' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Boolean',
                                  'blank' => false,
                                  'default' => false,
                                  'help_text' => 'If this revision is the latest, we mark it as being the head revision.',
                                  'index' => true,
                                  ),
                            'summary' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Varchar',
                                  'blank' => false,
                                  'size' => 250,
                                  'verbose' => __('summary'),
                                  'help_text' => __('A one line description of the changes.'),
                                  ),
                            'filesize' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Integer',
                                  'blank' => false,
                                  'default' => 0,
                                  'verbose' => __('file size in bytes'),
                                  ),
                            'submitter' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'Pluf_User',
                                  'blank' => false,
                                  'verbose' => __('submitter'),
                                  'relate_name' => 'submitted_downloads',
                                  ),
                            'pageusage' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Manytomany',
                                  'model' => 'IDF_Wiki_PageRevision',
                                  'blank' => true,
                                  'verbose' => __('page usage'),
                                  'help_text' => 'Records on which pages this resource revision is used.',
                                  ),
                            'creation_dtime' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Datetime',
                                  'blank' => true,
                                  'verbose' => __('creation date'),
                                  ),
                            );
    }

    function __toString()
    {
        return sprintf(__('id %d: %s'), $this->id, $this->summary);
    }

    function _toIndex()
    {
        return '';
    }

    function preSave($create=false)
    {
        if ($this->id == '') {
            $this->creation_dtime = gmdate('Y-m-d H:i:s');
        }
    }

    function postSave($create=false)
    {
        if ($create) {
            IDF_Timeline::insert($this, $this->get_project(),
                                 $this->get_submitter(), $this->creation_dtime);
        }
    }

    function getAbsoluteUrl($project)
    {
        return Pluf::f('url_upload').'/'.$project->shortname.'/files/'.$this->file;
    }

    function getFullPath()
    {
        return(Pluf::f('upload_path').'/'.$this->get_project()->shortname.'/files/'.$this->file);
    }

    /**
     * We drop the information from the timeline.
     */
    function preDelete()
    {
        IDF_Timeline::remove($this);
        @unlink(Pluf::f('upload_path').'/'.$this->project->shortname.'/files/'.$this->file);
    }

    /**
     * Returns the timeline fragment for the file.
     *
     *
     * @param Pluf_HTTP_Request
     * @return Pluf_Template_SafeString
     */
    public function timelineFragment($request)
    {
        $url = Pluf_HTTP_URL_urlForView('IDF_Views_Download::view',
                                        array($request->project->shortname,
                                              $this->id));
        $out = '<tr class="log"><td><a href="'.$url.'">'.
            Pluf_esc(Pluf_Template_dateAgo($this->creation_dtime, 'without')).
            '</a></td><td>';
        $stag = new IDF_Template_ShowUser();
        $user = $stag->start($this->get_submitter(), $request, '', false);
        $out .= sprintf(__('<a href="%1$s" title="View download">Download %2$d</a>, %3$s'), $url, $this->id, Pluf_esc($this->summary)).'</td>';
        $out .= '</tr>';
        $out .= "\n".'<tr class="extra"><td colspan="2">
<div class="helptext right">'.sprintf(__('Addition of <a href="%s">download&nbsp;%d</a>, by %s'), $url, $this->id, $user).'</div></td></tr>';
        return Pluf_Template::markSafe($out);
    }

    public function feedFragment($request)
    {
        $url = Pluf::f('url_base')
            .Pluf_HTTP_URL_urlForView('IDF_Views_Download::view',
                                      array($request->project->shortname,
                                            $this->id));
        $title = sprintf(__('%s: Download %d added - %s'),
                         $request->project->name,
                         $this->id, $this->summary);
        $date = Pluf_Date::gmDateToGmString($this->creation_dtime);
        $context = new Pluf_Template_Context_Request(
                       $request,
                       array('url' => $url,
                             'title' => $title,
                             'file' => $this,
                             'date' => $date)
                                                     );
        $tmpl = new Pluf_Template('idf/downloads/feedfragment.xml');
        return $tmpl->render($context);
    }
}
