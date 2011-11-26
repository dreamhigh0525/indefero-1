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
            $this->is_head = true;
        }
    }

    function postSave($create=false)
    {
        if ($create) {
            $sql = new Pluf_SQL('wikiresource=%s', array($this->wikiresource));
            $rev = Pluf::factory('IDF_Wiki_ResourceRevision')->getList(array('filter'=>$sql->gen()));
            if ($rev->count() > 1) {
                foreach ($rev as $r) {
                    if ($r->id != $this->id and $r->is_head) {
                        $r->is_head = false;
                        $r->update();
                    }
                }
            }
            // update the modification timestamp
            $resource = $this->get_wikiresource();
            $resource->update();
        }
    }

    function getFilePath()
    {
        return sprintf(Pluf::f('upload_path').'/'.$this->get_wikiresource()->get_project()->shortname.'/wiki/res/%d/%d.%s',
            $this->get_wikiresource()->id, $this->id, $this->get_wikiresource()->orig_file_ext);
    }

    function getFileURL()
    {
        $prj = $this->get_wikiresource()->get_project();
        return Pluf_HTTP_URL_urlForView('IDF_Views_Wiki::rawResource',
                                        array($prj->shortname, $this->id));
    }

    function preDelete()
    {
        @unlink($this->getFilePath());
    }

    /**
     * Returns the page revisions which contain references to this resource revision
     */
    function getPageRevisions()
    {
        $db =& Pluf::db();
        $sql_results = $db->select(
            'SELECT idf_wiki_pagerevision_id as id '.
            'FROM '.Pluf::f('db_table_prefix', '').'idf_wiki_pagerevision_idf_wiki_resourcerevision_assoc '.
            'WHERE idf_wiki_resourcerevision_id='.$this->id
        );
        $ids = array(0);
        foreach ($sql_results as $id) {
            $ids[] = $id['id'];
        }
        $ids = implode (',', $ids);

        $sql = new Pluf_SQL('id IN ('.$ids.')');
        return Pluf::factory('IDF_Wiki_PageRevision')
            ->getList(array('filter' => $sql->gen()));
    }

    /**
     * Renders the resource
     */
    function render()
    {
        $url = $this->getFileURL();
        $resource = $this->get_wikiresource();
        if (preg_match('#^image/(gif|jpeg|png|tiff)$#', $resource->mime_type)) {
            return sprintf('<a href="%s"><img src="%s" alt="%s" /></a>', $url, $url, $resource->title);
        }

        if (preg_match('#^text/(xml|html|sgml|javascript|ecmascript|css)$#', $resource->mime_type)) {
            return sprintf('<iframe src="%s" alt="%s"></iframe>', $url, $resource->title);
        }

        return __('Unable to render preview for this MIME type.');
    }
}
