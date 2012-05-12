# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2010 Céondo Ltd and contributors.
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

#   Installation of external tools : transifex-client
#   sudo apt-get install python-setuptools
#   sudo easy_install -U transifex-client



.PHONY: help
help:
	@printf "Rules for generating distributable files :\n"
	@for b in `git branch | sed "s/^. //g"`; do \
		printf "\t"$$b"-zipfile - Generate a zip archive of the "$$b" branch.\n"; \
	done
	@printf "\nRules for internationalization :\n";
	@printf "\tpot-update - Update the POT file from HTML templates and PHP sources, then merge it with PO file.\n"
	@printf "\tpot-push   - Send the POT file to the transifex server.\n"
	@printf "\tpo-update  - Merge the POT file into the PO file. The POT is not regenerated.\n"
	@printf "\tpo-push    - Send the all PO files to the transifex server.\n"
	@printf "\tpo-pull    - Get all PO files from the transifex server.\n"
	@printf "\tpo-stats   - Show translation statistics of all PO files.\n"
	@printf "\nRules for managing the database :\n";
	@printf "\tdb-install	- Install the database schema.\n"
	@printf "\tdb-update	- Update the database schema.\n"
	@printf "\tdb-backup-foo	- Create a named backup 'foo' with data from the current database.\n"
	@printf "\tdb-restore-foo	- Restore schema and the data from a named backup 'foo' to an empty database.\n"

#
#   Internationalization rule, POT & PO file manipulation
#
.PHONY: pluf_path
pluf_path:
ifeq (src/IDF/conf/path.php, $(wildcard src/IDF/conf/path.php))
PLUF_PATH=$(shell php -r "require_once('src/IDF/conf/path.php'); echo PLUF_PATH;")
else
	@printf "File 'src/IDF/conf/path.php' don't exist. Please configure it !\n"
	@exit 1
endif
 
.PHONY: pot-update po-update
pot-update: pluf_path
	# Backup pot file
	@if [ -e src/IDF/locale/idf.pot ]; then                     \
	mv -f src/IDF/locale/idf.pot src/IDF/locale/idf.pot.bak;    \
	fi
	touch src/IDF/locale/idf.pot;
	# Extract string
	@cd src; php "$(PLUF_PATH)/extracttemplates.php" IDF/conf/idf.php IDF/gettexttemplates
	@cd src; for phpfile in `find . -iname "*.php"`; do \
		printf "Parsing file : "$$phpfile"\n"; \
		xgettext -o idf.pot -p ./IDF/locale/ --from-code=UTF-8 -j \
			--keyword --keyword=__ --keyword=_n:1,2 -L PHP "$$phpfile" ; \
		done
	#	Remove tmp folder
	rm -Rf src/IDF/gettexttemplates
	# Update PO
	@make po-update

po-update: pluf_path
	@for pofile in `ls src/IDF/locale/*/idf.po`; do \
		printf "Updating file : "$$pofile"\n"; \
		msgmerge -v -U "$$pofile" src/IDF/locale/idf.pot; \
		printf "\n"; \
	done

#
#   Transifex
#
.PHONY: check-tx-config
check-tx-config:
	@if [ ! -e .tx/config ]; then                                       \
	mkdir -p .tx;                                                       \
	touch .tx/config;                                                   \
	printf "[main]\n" >> .tx/config;                                        \
	printf "host = http://www.transifex.net\n" >> .tx/config;               \
	printf "\n" >> .tx/config;                                              \
	printf "[indefero.idfpot]\n" >> .tx/config;                             \
	printf "file_filter = src/IDF/locale/<lang>/idf.po\n" >> .tx/config;    \
	printf "source_file = src/IDF/locale/idf.pot\n" >> .tx/config;          \
	printf "source_lang = en\n" >> .tx/config;                              \
	fi
	@if [ ! -e $(HOME)/.transifexrc ]; then					\
	touch $(HOME)/.transifexrc;												\
	printf "[http://www.transifex.net]\n" >> $(HOME)/.transifexrc;						\
	printf "username = \n" >> $(HOME)/.transifexrc;								\
	printf "token = \n" >> $(HOME)/.transifexrc;								\
	printf "password = \n" >> $(HOME)/.transifexrc;								\
	printf "hostname = http://www.transifex.net\n" >> $(HOME)/.transifexrc;					\
	printf "You must edit the file ~/.transifexrc to setup your transifex account (login & password) !\n";	\
	exit 1;																\
	fi

pot-push: check-tx-config
	@tx push -s

po-push: check-tx-config
	@tx push -t

po-pull: check-tx-config
	# Save PO
	@for pofile in `ls src/IDF/locale/*/idf.po`; do \
	    cp $$pofile $$pofile".save"; \
	done
	# Get new one
	@tx pull -a
	# Merge Transifex PO into local PO (so fuzzy entry is correctly saved)
	@for pofile in `ls src/IDF/locale/*/idf.po`; do \
	    msgmerge -U $$pofile".save" $$pofile; \
	    rm -f $$pofile; \
	    mv $$pofile".save" $$pofile; \
	done

po-stats:
	@msgfmt --statistics -v src/IDF/locale/idf.pot
	@for pofile in `ls src/IDF/locale/*/idf.po`; do \
	    msgfmt --statistics -v $$pofile; \
	done

#
#   Generic rule to build a zipfile of indefero for a specified branch
#   ex: make master_zipfile
#       make develop_zipfile
#
%-zipfile:
	@git archive --format=zip --prefix="indefero/" $* \
		> indefero-$*-`git log $* -n 1 \
		--pretty=format:%h`.zip

db-install:
	@cd src && php "$(PLUF_PATH)/migrate.php" --conf=IDF/conf/idf.php -a -d -i

db-update:
	@cd src && php "$(PLUF_PATH)/migrate.php" --conf=IDF/conf/idf.php -a -d

db-backup-%:
	@[ -e backups ] || mkdir backups
	@cd src && php "$(PLUF_PATH)/migrate.php" --conf=IDF/conf/idf.php -a -b ../backups $*
	@echo Files for named backup $* have been saved into backups/ directory.

db-restore-%:
	@cd src && php "$(PLUF_PATH)/migrate.php" --conf=IDF/conf/idf.php -a -r ../backups $*
	@echo Files for named backup $* have been restored from the backups/ directory.

