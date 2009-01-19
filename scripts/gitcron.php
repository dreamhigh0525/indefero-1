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
 * This script is used to control the access to the git repositories
 * using a restricted shell access.
 *
 * The only argument must be the login of the user.
 */
// Set the include path to have Pluf and IDF in it.
$indefero_path = dirname(__FILE__).'/../src';
//$pluf_path = '/path/to/pluf/src';
set_include_path(get_include_path()
                 .PATH_SEPARATOR.$indefero_path
//                 .PATH_SEPARATOR.$pluf_path
                 );
require 'Pluf.php';
Pluf::start(dirname(__FILE__).'/../src/IDF/conf/idf.php');
Pluf_Dispatcher::loadControllers(Pluf::f('idf_views'));
IDF_Plugin_SyncGit_Cron::main();

