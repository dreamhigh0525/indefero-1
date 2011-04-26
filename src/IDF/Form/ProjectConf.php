<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright(C) 2008-2011 Céondo Ltd and contributors.
#
# InDefero is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
#(at your option) any later version.
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
 * Configuration of the project.
 */
class IDF_Form_ProjectConf extends Pluf_Form
{
    public $project = null;

    public function initFields($extra=array())
    {
        $this->project = $extra['project'];

        // Basic part
        $this->fields['name'] = new Pluf_Form_Field_Varchar(array('required' => true,
                                                                  'label' => __('Name'),
                                                                  'initial' => $this->project->name,
                                                                 ));
        $this->fields['shortdesc'] = new Pluf_Form_Field_Varchar(array('required' => true,
                                                                       'label' => __('Short Description'),
                                                                       'initial' => $this->project->shortdesc,
                                                                       'widget_attrs' => array('size' => '68'),
                                                                      ));
        $this->fields['description'] = new Pluf_Form_Field_Varchar(array('required' => true,
                                                                         'label' => __('Description'),
                                                                         'initial' => $this->project->description,
                                                                         'widget_attrs' => array('cols' => 68,
                                                                                                 'rows' => 26,
                                                                                                ),
                                                                         'widget' => 'Pluf_Form_Widget_TextareaInput',
                                                                        ));
                                                                        
        // Logo part
        $upload_path = Pluf::f('upload_path', false);
        if (false === $upload_path) {
            throw new Pluf_Exception_SettingError(__('The "upload_path" configuration variable was not set.'));
        }
        $upload_path .= '/' . $this->project->shortname;
        $filename = '/%s'; 
        $this->fields['logo'] = new Pluf_Form_Field_File(array('required' => false,
                                                         'label' => __('Update the logo'),
                                                         'initial' => '',
                                                         'help_text' => __('The logo must be a picture with a size of 32 by 32.'),
                                                         'max_size' => Pluf::f('max_upload_size', 5 * 1024),
                                                         'move_function_params' => 
                                                         array('upload_path' => $upload_path,
                                                               'upload_path_create' => true,
                                                               'file_name' => $filename,
                                                               )
                                                         ));
                                                         
        $this->fields['logo_remove'] = new Pluf_Form_Field_Boolean(array('required' => false,
                                                                         'label' => __('Remove the current logo'),
                                                                         'initial' => false,
                                                                         'widget' => 'Pluf_Form_Widget_CheckboxInput',
                                                                         ));
    }
    
    /**
     * If we have uploaded a file, but the form failed remove it.
     *
     */
    function failed()
    {
        if (!empty($this->cleaned_data['logo']) 
            && file_exists(Pluf::f('upload_path').'/'.$this->cleaned_data['logo'])) {
            unlink(Pluf::f('upload_path').'/'.$this->cleaned_data['logo']);
        }
    }
    
    public function clean()
    {
        if (!isset($this->cleaned_data['logo_remove'])) {
            $this->cleaned_data['logo_remove'] = false;
        }
        
        return $this->cleaned_data;
    }
    
    public function clean_logo()
    {
        if (empty($this->cleaned_data['logo'])) {
            return '';
        }
        
        $meta = getimagesize(Pluf::f('upload_path') . '/' . $this->project->shortname . $this->cleaned_data['logo']);
        
        if ($meta === false) {
            throw new Pluf_Form_Invalid("Could not determine the size of the uploaded picture.");
        }
        
        if ($meta[0] !== 32 || $meta[1] !== 32) {
            throw new Pluf_Form_Invalid("The picture must have a size of 32 by 32.");
        }

        return $this->cleaned_data['logo'];
    }

    public function save($commit=true)
    {
        $conf = $this->project->getConf();
        
        // Basic part
        $this->project->name = $this->cleaned_data['name'];
        $this->project->shortdesc = $this->cleaned_data['shortdesc'];
        $this->project->description = $this->cleaned_data['description'];
        $this->project->update();
        
        // Logo part
        if ($this->cleaned_data['logo'] !== "") {
            $conf->setVal('logo', $this->cleaned_data['logo']);
        }
        if ($this->cleaned_data['logo_remove'] === true) {
            @unlink(Pluf::f('upload_path') . '/' . $this->project->shortname . $conf->getVal('logo'));
            $conf->delVal('logo');
        }
    }
}
