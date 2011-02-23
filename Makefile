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

PLUF_PATH=$(shell php -r "require_once('src/IDF/conf/path.php'); echo PLUF_PATH;")

all help:
	@echo "Rules for generate tarball :"
	@for b in `git branch | sed "s/^. //g"`; do \
		echo -e "\t"$$b"_tarball - Generate a zip archive of the "$$b" branch."; \
	done
	@echo -e "\nRules for internationnalization :";
	@echo -e "\tpot-update - Update the POT file from HTML template and PHP source, then merge it with PO file"
	@echo -e "\tpot-push - Send the POT file on transifex server"
	@echo -e "\tpo-update - Merge POT file into PO file. POT is not regenerated."
	@echo -e "\tpo-push - Send the all PO file on transifex server"
	@echo -e "\tpo-pull - Get all PO file from transifex server"

#
#   Internationnalization rule, POT & PO file manipulation
#   
.PHONY: pot-update po-update
pot-update:
	# Backup pot file
	@if [ -e src/IDF/locale/idf.pot ]; then                     \
	mv -f src/IDF/locale/idf.pot src/IDF/locale/idf.pot.bak;    \
	fi
	touch src/IDF/locale/idf.pot;
	# Extract string
	@cd src; php $(PLUF_PATH)/extracttemplates.php IDF/conf/idf.php IDF/gettexttemplates
	@cd src; for phpfile in `find . -iname "*.php"`; do \
		echo "Parsing file : "$$phpfile; \
		xgettext -o idf.pot -p ./IDF/locale/ --from-code=UTF-8 -j --keyword --keyword=__ --keyword=_n:1,2 -L PHP $$phpfile ; \
		done
	#	Remove tmp folder
	rm -Rf src/IDF/gettexttemplates
	# Update PO
	@make po-update

po-update:
	@for pofile in `ls src/IDF/locale/*/idf.po`; do \
		echo "Updating file : "$$pofile; \
		msgmerge -v -U $$pofile src/IDF/locale/idf.pot; \
		echo ; \
	done

#
#   Transifex
#
.PHONY: check-tx-config
check-tx-config:
	@if [ ! -e .tx/config ]; then                                       \
	mkdir -p .tx;                                                       \
	touch .tx/config;                                                   \
	echo "[main]" >> .tx/config;                                        \
	echo "host = http://www.transifex.net" >> .tx/config;               \
	echo "" >> .tx/config;                                              \
	echo "[indefero.idfpot]" >> .tx/config;                             \
	echo "file_filter = src/IDF/locale/<lang>/idf.po" >> .tx/config;    \
	echo "source_file = src/IDF/locale/idf.pot" >> .tx/config;          \
	echo "source_lang = en" >> .tx/config;                              \
	fi
	@if [ ! -e $(HOME)/.transifexrc ]; then								\
	touch $(HOME)/.transifexrc;												\
	echo "[http://www.transifex.net]" >> $(HOME)/.transifexrc;				\
	echo "username = " >> $(HOME)/.transifexrc;								\
	echo "token = " >> $(HOME)/.transifexrc;									\
	echo "password = " >> $(HOME)/.transifexrc;								\
	echo "hostname = http://www.transifex.net" >> $(HOME)/.transifexrc;		\
	echo "You must edit the file ~/.transifexrc to setup your transifex account (login & password) !";		\
	exit 1;																\
	fi

pot-push: check-tx-config
	@tx push -s

po-push: check-tx-config
	@tx push -t

po-pull: check-tx-config
	@tx pull -a

#
#   Generic rule to build a tarball of indefero for a specified branch
#   ex: make master_tarball
#       make dev_tarball
#
%_tarball:
	@git archive --format=zip --prefix="indefero/" $(@:_tarball=) > indefero-$(@:_tarball=)-`git log $(@:_tarball=) -n 1 --pretty=format:%H`.zip

