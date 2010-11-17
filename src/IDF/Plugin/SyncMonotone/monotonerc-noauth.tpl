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
    local read_only_commands = {
        "get_corresponding_path", "get_content_changed", "tags", "branches",
        "common_ancestors", "packet_for_fdelta", "packet_for_fdata",
        "packets_for_certs", "packet_for_rdata", "get_manifest_of",
        "get_revision", "select", "graph", "children", "parents", "roots",
        "leaves", "ancestry_difference", "toposort", "erase_ancestors",
        "descendents", "ancestors", "heads", "get_file_of", "get_file",
        "interface_version", "get_attributes", "content_diff",
        "file_merge", "show_conflicts", "certs", "keys", "get_file_size",
        "get_extended_manifest_of"
    }

    for _,v in ipairs(read_only_commands) do
        if (v == command[1]) then
            return true
        end
    end

    return false
end

--
-- let IDF know of new arriving revisions to fill its timeline
--
_idf_revs = {}
push_hook_functions({
    ["start"] = function (session_id)
        _idf_revs[session_id] = {}
        return "continue",nil
    end,
    ["revision_received"] = function (new_id, revision, certs, session_id)
        table.insert(_idf_revs[session_id], new_id)
        return "continue",nil
    end,
    ["end"] = function (session_id, ...)
        if table.getn(_idf_revs[session_id]) == 0 then
           return "continue",nil
        end

        local pin,pout,pid = spawn_pipe("%%MTNPOSTPUSH%%", "%%PROJECT%%");
        if pid == -1 then
            print("could not execute %%MTNPOSTPUSH%%")
            return
        end

        for _,r in ipairs(_idf_revs[session_id]) do
           pin:write(r .. "\n")
        end
        pin:close()

        wait(pid)
        return "continue",nil
    end
})

--
-- Load local hooks if they exist.
--
-- The way this is supposed to work is that hooks.d can contain symbolic
-- links to lua scripts.  These links MUST have the extension .lua
-- If the script needs some configuration, a corresponding file with
-- the extension .conf is the right spot.
--  
-- First load the configuration of the hooks, if applicable
includedirpattern(get_confdir() .. "/hooks.d/", "*.conf")
-- Then load the hooks themselves
includedirpattern(get_confdir() .. "/hooks.d/", "*.lua")
