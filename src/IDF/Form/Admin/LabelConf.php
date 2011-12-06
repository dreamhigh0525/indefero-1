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
 * Configuration of forge labels.
 */
class IDF_Form_Admin_LabelConf extends Pluf_Form
{
    const init_project_labels = 'UI:GUI = Applications with graphical user interfaces
UI:CLI = Applications with no graphical user interfaces
License:BSD = Applications with BSD license
License:GPL = Applications with GPL license
';

    public function initFields($extra=array())
    {
               $this->fields['project_labels'] = new Pluf_Form_Field_Varchar(
                                      array('required' => true,
                                            'label' => __('Predefined project labels'),
                                            'initial' => self::init_project_labels,
                                            'widget_attrs' => array('rows' => 13,
                                                                    'cols' => 75),
                                            'widget' => 'Pluf_Form_Widget_TextareaInput',
                                            ));
    }
}
