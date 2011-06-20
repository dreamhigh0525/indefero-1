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

class IDF_ProjectTest extends PHPUnit_Framework_TestCase
{
    public function testGetIssueCountByOwner()
    {
        // Add users
        $user1 = new Pluf_User();
        $user1->login = 'user1';
        $user1->create();
        $user2 = new Pluf_User();
        $user2->login = 'user2';
        $user2->create();
        
        // Add a project
        $prj = new IDF_Project();
        $prj->create();
        $tag = $prj->getTagIdsByStatus('open');
  
        // First test with no issue
        $stats = $prj->getIssueCountByOwner();
        $this->assertEquals($stats, array());
        
        // Add some issues
        $issue1 = new IDF_Issue();
        $issue1->project = $prj;
        $issue1->submitter = $user1;
        $issue1->owner = $user1;
        $issue1->status = new IDF_Tag($tag[0]);
        $issue1->create();
        
        $issue2 = new IDF_Issue();
        $issue2->project = $prj;
        $issue2->submitter = $user2;
        $issue2->owner = $user1;
        $issue2->status = new IDF_Tag($tag[0]);
        $issue2->create();        

        $issue3 = new IDF_Issue();
        $issue3->project = $prj;
        $issue3->submitter = $user2;
        $issue3->status = new IDF_Tag($tag[0]);
        $issue3->create();          

        $issue4 = new IDF_Issue();
        $issue4->project = $prj;
        $issue4->submitter = $user2;
        $issue4->owner = $user2;
        $issue4->status = new IDF_Tag($tag[0]);
        $issue4->create();    
                
        // 2nd test
        $stats = $prj->getIssueCountByOwner();
        $expected = array(0          => 1,
                          $user2->id => 1,
                          $user1->id => 2);
        $this->assertEquals($stats, $expected);

        // Clean DB
        $issue4->delete();
        $issue3->delete();
        $issue2->delete();
        $issue1->delete();
        $prj->delete();
        $user2->delete();
        $user1->delete();
    }
}

