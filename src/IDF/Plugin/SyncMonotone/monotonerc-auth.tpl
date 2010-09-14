-- ***** BEGIN LICENSE BLOCK *****
-- This file is part of InDefero, an open source project management application.
-- Copyright (C) 2010 Céondo Ltd and contributors.
--
-- InDefero is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 2 of the License, or
-- (at your option) any later version.
--
-- InDefero is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program; if not, write to the Free Software
-- Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
--
-- ***** END LICENSE BLOCK *****

--
-- controls the access rights for remote_stdio which is used by IDFs frontend
-- and other interested parties
--
function get_remote_automate_permitted(key_identity, command, options)
    if (key_identity.id == "%%MTNCLIENTKEY%%") then
        return true
    end

    return false
end

--
-- let IDF know of new arriving revisions to fill its timeline
--
_idf_revs = {}
function note_netsync_start(session_id)
    _idf_revs[session_id] = {}
end

function note_netsync_revision_received(new_id, revision, certs, session_id)
    table.insert(_idf_revs[session_id], new_id)
end

function note_netsync_end (session_id, ...)
    if table.getn(_idf_revs[session_id]) == 0 then
        return
    end

    local pin,pout,pid = spawn_pipe("%%MTNPOSTPUSH%%", "%%PROJECT%%");
    if pid == -1 then
        print("could execute %%MTNPOSTPUSH%%")
        return
    end

    for _,r in ipairs(_idf_revs[session_id]) do
        pin:write(r .. "\n")
    end
    pin:close()

    wait(pid)
end

