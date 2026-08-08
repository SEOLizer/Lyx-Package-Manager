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
        lpm/cli/args.lyx \
        lpm/cli/commands.lyx

all: bin/lpm

bin/lpm: $(SRCS)
	mkdir -p bin
	$(LYXC) main.lyx -I $(ROOT) -I $(AURUM) -o bin/lpm

clean:
	rm -f bin/lpm

.PHONY: all clean

test: bin/resolver_test
	./bin/resolver_test

bin/resolver_test: tests/resolver_test.lyx lpm/core/resolver.lyx lpm/core/semver.lyx lpm/core/lockfile.lyx
	mkdir -p bin
	$(LYXC) tests/resolver_test.lyx -I $(ROOT) -I $(AURUM) -o bin/resolver_test

.PHONY: test
