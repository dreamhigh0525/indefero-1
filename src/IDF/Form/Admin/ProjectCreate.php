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
 * Create a project.
 *
 * A kind of merge of the member configuration, overview and the
 * former source tab.
 *
 */
class IDF_Form_Admin_ProjectCreate extends Pluf_Form
{
    public function initFields($extra=array())
    {
        $choices = array();
        $options = array(
                         'git' => __('git'),
                         'svn' => __('Subversion'),
                         'mercurial' => __('mercurial'),
                         'mtn' => __('monotone'),
                         );
        foreach (Pluf::f('allowed_scm', array()) as $key => $class) {
            $choices[$options[$key]] = $key;
        }

        $this->fields['name'] = new Pluf_Form_Field_Varchar(
                                      array('required' => true,
                                            'label' => __('Name'),
                                            'initial' => '',
                                            ));

        $this->fields['private_project'] = new Pluf_Form_Field_Boolean(
                    array('required' => false,
                          'label' => __('Private project'),
                          'initial' => false,
                          'widget' => 'Pluf_Form_Widget_CheckboxInput',
                          ));

        $this->fields['shortname'] = new Pluf_Form_Field_Varchar(
                                      array('required' => true,
                                            'label' => __('Shortname'),
                                            'initial' => '',
                                            'help_text' => __('It must be unique for each project and composed only of letters, digits and dash (-) like "my-project".'),
                                            ));

        $this->fields['shortdesc'] = new Pluf_Form_Field_Varchar(
                                      array('required' => true,
                                            'label' => __('Short description'),
                                            'help_text' => __('A one line description of the project.'),
                                            'initial' => '',
                                            'widget_attrs' => array('size' => '35'),
                                            ));

        $this->fields['external_project_url'] = new Pluf_Form_Field_Varchar(
                                    array('required' => false,
                                          'label' => __('External URL'),
                                          'widget_attrs' => array('size' => '35'),
                                          'initial' => '',
        ));

        $this->fields['scm'] = new Pluf_Form_Field_Varchar(
                    array('required' => true,
                          'label' => __('Repository type'),
                          'initial' => 'git',
                          'widget_attrs' => array('choices' => $choices),
                          'widget' => 'Pluf_Form_Widget_SelectInput',
                          ));

        $this->fields['svn_remote_url'] = new Pluf_Form_Field_Varchar(
                    array('required' => false,
                          'label' => __('Remote Subversion repository'),
                          'initial' => '',
                          'widget_attrs' => array('size' => '30'),
                          ));

        $this->fields['svn_username'] = new Pluf_Form_Field_Varchar(
                    array('required' => false,
                          'label' => __('Repository username'),
                          'initial' => '',
                          'widget_attrs' => array('size' => '15'),
                          ));

        $this->fields['svn_password'] = new Pluf_Form_Field_Varchar(
                    array('required' => false,
                          'label' => __('Repository password'),
                          'initial' => '',
                          'widget' => 'Pluf_Form_Widget_PasswordInput',
                          ));

        $this->fields['mtn_master_branch'] = new Pluf_Form_Field_Varchar(
                    array('required' => false,
                          'label' => __('Master branch'),
                          'initial' => '',
                          'widget_attrs' => array('size' => '35'),
                          'help_text' => __('This should be a world-wide unique identifier for your project. A reverse DNS notation like "com.my-domain.my-project" is a good idea.'),
                          ));

        $this->fields['owners'] = new Pluf_Form_Field_Varchar(
                                      array('required' => false,
                                            'label' => __('Project owners'),
                                            'initial' => $extra['user']->login,
                                            'widget' => 'Pluf_Form_Widget_TextareaInput',
                                            'widget_attrs' => array('rows' => 5,
                                                                    'cols' => 40),
                                            ));

        $this->fields['members'] = new Pluf_Form_Field_Varchar(
                                      array('required' => false,
                                            'label' => __('Project members'),
                                            'initial' => '',
                                            'widget_attrs' => array('rows' => 7,
                                                                    'cols' => 40),
                                            'widget' => 'Pluf_Form_Widget_TextareaInput',
                                            ));

        $projects = array('--' => '--');
        foreach (Pluf::factory('IDF_Project')->getList(array('order' => 'name ASC')) as $proj) {
            $projects[$proj->name] = $proj->shortname;
        }
        $this->fields['template'] = new Pluf_Form_Field_Varchar(
                                      array('required' => false,
                                            'label' => __('Project template'),
                                            'initial' => '--',
                                            'help_text' => __('Use the given project to initialize the new project. Access rights and general configuration will be taken from the template project.'),
                                            'widget' => 'Pluf_Form_Widget_SelectInput',
                                            'widget_attrs' => array('choices' => $projects),
                                            ));

        /**
         * [signal]
         *
         * IDF_Form_Admin_ProjectCreate::initFields
         *
         * [sender]
         *
         * IDF_Form_Admin_ProjectCreate
         *
         * [description]
         *
         * This signal allows an application to modify the form
         * for the creation of a project.
         *
         * [parameters]
         *
         * array('form' => $form)
         *
         */
        $params = array('form' => $this);
        Pluf_Signal::send('IDF_Form_Admin_ProjectCreate::initFields',
                          'IDF_Form_Admin_ProjectCreate', $params);
    }

    public function clean_owners()
    {
        return IDF_Form_MembersConf::checkBadLogins($this->cleaned_data['owners']);
    }

    public function clean_members()
    {
        return IDF_Form_MembersConf::checkBadLogins($this->cleaned_data['members']);
    }

    public function clean_svn_remote_url()
    {
        $this->cleaned_data['svn_remote_url'] = (!empty($this->cleaned_data['svn_remote_url'])) ? $this->cleaned_data['svn_remote_url'] : '';
        $url = trim($this->cleaned_data['svn_remote_url']);
        if (strlen($url) == 0) return $url;
        // we accept only starting with http(s):// to avoid people
        // trying to access the local filesystem.
        if (!preg_match('#^(http|https)://#', $url)) {
            throw new Pluf_Form_Invalid(__('Only a remote repository available through HTTP or HTTPS is allowed. For example "http://somewhere.com/svn/trunk".'));
        }
        return $url;
    }

    public function clean_mtn_master_branch()
    {
        // do not validate, but empty the field if a different
        // SCM should be used
        if ($this->cleaned_data['scm'] != 'mtn')
            return '';

        $mtn_master_branch = mb_strtolower($this->cleaned_data['mtn_master_branch']);
        if (!preg_match('/^([\w\d]+([-][\w\d]+)*)(\.[\w\d]+([-][\w\d]+)*)*$/',
                        $mtn_master_branch)) {
            throw new Pluf_Form_Invalid(__(
                'The master branch is empty or contains illegal characters, '.
                'please use only letters, digits, dashes and dots as separators.'
            ));
        }

        $sql = new Pluf_SQL('vkey=%s AND vdesc=%s',
                            array('mtn_master_branch', $mtn_master_branch));
        $l = Pluf::factory('IDF_Conf')->getList(array('filter'=>$sql->gen()));
        if ($l->count() > 0) {
            throw new Pluf_Form_Invalid(__(
                'This master branch is already used. Please select another one.'
            ));
        }

        return $mtn_master_branch;
    }

    public function clean_shortname()
    {
        $shortname = mb_strtolower($this->cleaned_data['shortname']);
        if (preg_match('/[^\-A-Za-z0-9]/', $shortname)) {
            throw new Pluf_Form_Invalid(__('This shortname contains illegal characters, please use only letters, digits and dash (-).'));
        }
        if (mb_substr($shortname, 0, 1) == '-') {
            throw new Pluf_Form_Invalid(__('The shortname cannot start with the dash (-) character.'));
        }
        if (mb_substr($shortname, -1) == '-') {
            throw new Pluf_Form_Invalid(__('The shortname cannot end with the dash (-) character.'));
        }
        $sql = new Pluf_SQL('shortname=%s', array($shortname));
        $l = Pluf::factory('IDF_Project')->getList(array('filter'=>$sql->gen()));
        if ($l->count() > 0) {
            throw new Pluf_Form_Invalid(__('This shortname is already used. Please select another one.'));
        }
        return $shortname;
    }

    public function clean_external_project_url()
    {
        return IDF_Form_ProjectConf::checkWebURL($this->cleaned_data['external_project_url']);
    }

    public function clean()
    {
        if ($this->cleaned_data['scm'] != 'svn') {
            foreach (array('svn_remote_url', 'svn_username', 'svn_password')
                     as $key) {
                $this->cleaned_data[$key] = '';
            }
        }

        if ($this->cleaned_data['scm'] != 'mtn') {
            $this->cleaned_data['mtn_master_branch'] = '';
        }

        /**
         * [signal]
         *
         * IDF_Form_Admin_ProjectCreate::clean
         *
         * [sender]
         *
         * IDF_Form_Admin_ProjectCreate
         *
         * [description]
         *
         * This signal allows an application to clean the form
         * for the creation of a project.
         *
         * [parameters]
         *
         * array('cleaned_data' => $cleaned_data)
         *
         */
        $params = array('cleaned_data' => $this->cleaned_data);
        Pluf_Signal::send('IDF_Form_Admin_ProjectCreate::clean',
                          'IDF_Form_Admin_ProjectCreate', $params);
        return $this->cleaned_data;
    }

    public function save($commit=true)
    {
        if (!$this->isValid()) {
            throw new Exception(__('Cannot save the model from an invalid form.'));
        }
        $project = new IDF_Project();
        $project->name = $this->cleaned_data['name'];
        $project->shortname = $this->cleaned_data['shortname'];
        $project->shortdesc = $this->cleaned_data['shortdesc'];

        if ($this->cleaned_data['template'] != '--') {
            // Find the template project
            $sql = new Pluf_SQL('shortname=%s',
                                array($this->cleaned_data['template']));
            $tmpl = Pluf::factory('IDF_Project')->getOne(array('filter' => $sql->gen()));
            $project->private = $tmpl->private;
            $project->description = $tmpl->description;
        } else {
            $project->private = $this->cleaned_data['private_project'];
            $project->description = __('Click on the Project Management tab to set the description of your project.');
        }
        $project->create();
        $conf = new IDF_Conf();
        $conf->setProject($project);
        $keys = array('scm', 'svn_remote_url', 'svn_username',
                      'svn_password', 'mtn_master_branch', 'external_project_url');
        foreach ($keys as $key) {
            $this->cleaned_data[$key] = (!empty($this->cleaned_data[$key])) ?
                $this->cleaned_data[$key] : '';
            $conf->setVal($key, $this->cleaned_data[$key]);
        }
        if ($this->cleaned_data['template'] != '--') {
            $tmplconf = new IDF_Conf();
            $tmplconf->setProject($tmpl);
            // We need to get all the configuration variables we want from
            // the old project and put them into the new project.
            $props = array(
                           'labels_download_predefined' => IDF_Form_UploadConf::init_predefined,
                           'labels_download_one_max' => IDF_Form_UploadConf::init_one_max,
                           'labels_wiki_predefined' => IDF_Form_WikiConf::init_predefined,
                           'labels_wiki_one_max' => IDF_Form_WikiConf::init_one_max,
                           'labels_issue_template' => IDF_Form_IssueTrackingConf::init_template,
                           'labels_issue_open' => IDF_Form_IssueTrackingConf::init_open,
                           'labels_issue_closed' => IDF_Form_IssueTrackingConf::init_closed,
                           'labels_issue_predefined' =>  IDF_Form_IssueTrackingConf::init_predefined,
                           'labels_issue_one_max' => IDF_Form_IssueTrackingConf::init_one_max,
                           'issue_relations' => IDF_Form_IssueTrackingConf::init_relations,
                           'webhook_url' => '',
                           'downloads_access_rights' => 'all',
                           'review_access_rights' => 'all',
                           'wiki_access_rights' => 'all',
                           'source_access_rights' => 'all',
                           'issues_access_rights' => 'all',
                           'downloads_notification_email' => '',
                           'review_notification_email' => '',
                           'wiki_notification_email' => '',
                           'source_notification_email' => '',
                           'issues_notification_email' => '',
                           );
            foreach ($props as $prop => $def) {
                $conf->setVal($prop, $tmplconf->getVal($prop, $def));
            }
        }
        $project->created();

        if ($this->cleaned_data['template'] == '--') {
            IDF_Form_MembersConf::updateMemberships($project,
                                                    $this->cleaned_data);
        } else {
            // Get the membership of the template $tmpl
            IDF_Form_MembersConf::updateMemberships($project,
                                                    $tmpl->getMembershipData('string'));
        }
        $project->membershipsUpdated();
        return $project;
    }

    /**
     * Check that the template project exists.
     */
    public function clean_template()
    {
        if ($this->cleaned_data['template'] == '--') {
            return $this->cleaned_data['template'];
        }
        $sql = new Pluf_SQL('shortname=%s', array($this->cleaned_data['template']));
        if (Pluf::factory('IDF_Project')->getOne(array('filter' => $sql->gen())) == null) {
            throw new Pluf_Form_Invalid(__('This project is not available.'));
        }
        return $this->cleaned_data['template'];
    }
}


