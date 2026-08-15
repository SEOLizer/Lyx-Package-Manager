LYXC   := /home/andreas/PhpstormProjects/aurum/lyxc
AURUM  := /home/andreas/PhpstormProjects/aurum
ROOT   := /home/andreas/PhpstormProjects/lyx-lpm

SRCS := main.lyx \
        lpm/core/semver.lyx \
        lpm/core/manifest.lyx \
        lpm/core/lockfile.lyx \
        lpm/core/dirent.lyx \
        lpm/core/cache.lyx \
        lpm/core/archive.lyx \
        lpm/core/resolver.lyx \
        lpm/core/index.lyx \
        lpm/core/localcat.lyx \
        lpm/core/fetch.lyx \
        lpm/core/pack.lyx \
        lpm/core/credentials.lyx \
        lpm/cli/args.lyx \
        lpm/cli/commands.lyx

all: bin/lpm

bin/lpm: $(SRCS)
	mkdir -p bin
	$(LYXC) main.lyx -I $(ROOT) -I $(AURUM) -o bin/lpm

clean:
	rm -f bin/lpm

# Installation
#
# PREFIX und DESTDIR sind ueberschreibbar:
#   make install PREFIX=$$HOME/.local     # ohne sudo, in den eigenen Pfad
#   make install DESTDIR=/tmp/stage       # zum Paketbau, ohne das System zu beruehren
PREFIX  ?= /usr/local
BINDIR  ?= $(PREFIX)/bin
DESTDIR ?=

install: bin/lpm
	install -d $(DESTDIR)$(BINDIR)
	install -m 755 bin/lpm $(DESTDIR)$(BINDIR)/lpm
	@echo "lpm installiert nach $(DESTDIR)$(BINDIR)/lpm"

uninstall:
	rm -f $(DESTDIR)$(BINDIR)/lpm
	@echo "lpm aus $(DESTDIR)$(BINDIR) entfernt"

.PHONY: all clean install uninstall

test: bin/resolver_test bin/index_test bin/fetch_test bin/pack_test bin/credentials_test
	./bin/resolver_test
	./bin/index_test
	./bin/fetch_test
	./bin/pack_test
	./bin/credentials_test

bin/resolver_test: tests/resolver_test.lyx lpm/core/resolver.lyx lpm/core/semver.lyx lpm/core/lockfile.lyx
	mkdir -p bin
	$(LYXC) tests/resolver_test.lyx -I $(ROOT) -I $(AURUM) -o bin/resolver_test

bin/index_test: tests/index_test.lyx lpm/core/index.lyx lpm/core/resolver.lyx lpm/core/semver.lyx lpm/core/lockfile.lyx
	mkdir -p bin
	$(LYXC) tests/index_test.lyx -I $(ROOT) -I $(AURUM) -o bin/index_test

bin/fetch_test: tests/fetch_test.lyx lpm/core/fetch.lyx lpm/core/cache.lyx lpm/core/dirent.lyx
	mkdir -p bin
	$(LYXC) tests/fetch_test.lyx -I $(ROOT) -I $(AURUM) -o bin/fetch_test

bin/pack_test: tests/pack_test.lyx lpm/core/pack.lyx lpm/core/archive.lyx lpm/core/dirent.lyx lpm/core/manifest.lyx
	mkdir -p bin
	$(LYXC) tests/pack_test.lyx -I $(ROOT) -I $(AURUM) -o bin/pack_test

bin/credentials_test: tests/credentials_test.lyx lpm/core/credentials.lyx lpm/core/cache.lyx lpm/core/dirent.lyx
	mkdir -p bin
	$(LYXC) tests/credentials_test.lyx -I $(ROOT) -I $(AURUM) -o bin/credentials_test

.PHONY: test
