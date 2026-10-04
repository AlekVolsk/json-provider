#### Makefile global configuration
.PHONY: *
.DEFAULT_GOAL := help
SHELL = /bin/bash

# Configs
PHP_MEMORY_LIMIT ?= 1G

#### Predefined global variables/functions
# Colors for SH scripts. See https://www.shellhacks.com/bash-colors/
CE       = \033[0m
C_RED    = \033[0;31m
C_GREEN  = \033[0;32m
C_YELLOW = \033[0;33m
C_TITLE  = \033[0;30;46m

# Paths
PATH_ROOT  ?= `pwd`
PATH_SRC   ?= $(PATH_ROOT)/src
PATH_TESTS ?= $(PATH_ROOT)/tests
PATH_BUILD ?= $(PATH_ROOT)/build-dev

# Binary files
PHP_BIN      ?= php
PHP_BIN_CONF ?= XDEBUG_MODE=off $(PHP_BIN) -d max_execution_time=900 -d memory_limit=$(PHP_MEMORY_LIMIT)
VENDOR_BIN   ?= $(PHP_BIN_CONF) $(PATH_ROOT)/vendor/bin


#### Helper Functions
# Pretty print for `make help`
HELP_FUNCTION = \
    %help; while(<>){push@{$$help{$$2//'Misc'}},[$$1,$$3] \
    if/^([\w-_]+)\s*:.*\#\#(?:@(\w+))?\s(.*)$$/}; \
    print"$$_:\n", map"  $$_->[0]".(" "x(30-length($$_->[0])))."$$_->[1]\n", \
    @{$$help{$$_}},"\n" for keys %help;

# Render colored title before executing a command
define title
    @echo ""
    @echo -e "$(C_YELLOW)>>> $(C_TITLE) $(1) $(CE)"
endef


#### Makefile-level Actions ############################################################
help: ## To see this description
	@echo "Command line interface for JsonProvider dev actions."
	@echo ""
	@echo "Usage:"
	@echo "  * make [target]"
	@echo "  * ENV_VAR=value make [target]"
	@echo "  * make [target] OPTION_NAME=value"
	@echo ""
	@perl -e '$(HELP_FUNCTION)' $(MAKEFILE_LIST)
	@echo ""


list: ## Full list of targets
	@grep -hE '^[a-zA-Z][a-zA-Z0-9_-]*:' $(MAKEFILE_LIST) | sed 's/:.*//' | sort -u


#### Global Project Actions ############################################################
build: ##@Project Install PHP dependencies (dev) + prepare build dir
	$(call title,"Installing PHP dependencies - Dev Mode")
	@composer outdated --direct || make update
	@composer install --optimize-autoloader --no-progress --classmap-authoritative
	@mkdir -pv $(PATH_BUILD)


build-prod: ##@Project Install PHP dependencies - Production Mode
	$(call title,"Installing PHP dependencies - Production Mode")
	@composer install --optimize-autoloader --no-progress --classmap-authoritative --no-dev


update: ##@Project Upgrade PHP dependencies + update composer.lock
	$(call title,"Upgrading PHP dependencies")
	@composer update --optimize-autoloader --no-progress --with-dependencies --classmap-authoritative


autoload: ##@Project Dump the optimized autoloader
	$(call title,"Dumping the autoloader")
	@composer dump-autoload --optimize --no-interaction --classmap-authoritative


rebuild: ##@Project Re-build the project (clean + install)
	@make clean-vendor
	@make build


clean-vendor: ##@Project Cleanup vendor directory
	$(call title,"Cleanup vendor directory")
	@rm -fr $(PATH_ROOT)/vendor


test: ##@Testing Run all checks: cs-fixer, autoload, testo, phpcs, phpstan
	$(call title,"Running all checks")
	@make test-phpcsfixer
	@make autoload
	@make test-testo
	@make test-phpcs
	@make test-phpstan


#### PHPcs - CodeSniffer ##############################################################
test-phpcs: ##@Testing Check codebase via PHP_CodeSniffer (src + tests)
	$(call title,"Testing by PHP_CodeSniffer")
	@$(VENDOR_BIN)/phpcs --version
	@$(VENDOR_BIN)/phpcs \
        --standard="$(PATH_ROOT)/phpcs.xml" \
        --report=checkstyle \
        --report-file="$(PATH_BUILD)/phpcs-checkstyle.xml" \
        --parallel=8 \
        --cache \
        -p -s


#### Testo - Testing Framework ########################################################
compat-legacy: ##@Testing Extract the published v1.0.0 sources for the compatibility tests
	@if [ ! -f "$(PATH_BUILD)/compat/v1.0.0/src/JsonDataProvider.php" ]; then \
        mkdir -p "$(PATH_BUILD)/compat/v1.0.0" && \
        git -C "$(PATH_ROOT)" archive v1.0.0 src \
            | tar -x -C "$(PATH_BUILD)/compat/v1.0.0"; \
    fi


test-testo: compat-legacy ##@Testing Testo - unit tests (tests/Unit)
	$(call title,"Testo - unit tests")
	@$(VENDOR_BIN)/testo \
        --config="$(PATH_ROOT)/testo.php" \
        --type=!bench \
		--log-junit="$(PATH_BUILD)/testo-junit.xml"


# Benchmark files by group. Each file runs in its own testo process: one process
# for several files carries state from file to file and skews the numbers, pair
# ratios included. Groups use separate databases, so `make -j3 test-bench` runs
# them side by side in ~11 min; the groups then share CPU and disk, fsync-bound
# steps swing by up to 30% and pair ratios by up to 7 points, so reference
# numbers come from a sequential run.
BENCH_LOAD    = LoadBenchWrite LoadBenchDto LoadBenchArray LoadBenchMigration
BENCH_MAPPING = MappingBenchHydration MappingBenchQuery MappingBenchRelations MappingBenchSort
BENCH_INDEX   = IndexBench

# Run benchmark files one process each, report per file, fail if any file fails
define bench_files
    @status=0; for name in $(1); do \
        $(VENDOR_BIN)/testo \
            --config="$(PATH_ROOT)/testo.php" \
            --type=bench \
            --path="$(PATH_TESTS)/Bench/$$name.php" \
            --log-html="$(PATH_BUILD)/testo-bench-$$name.html" \
            -vvv || status=1; \
    done; exit $$status
endef


test-bench: test-bench-load test-bench-mapping test-bench-index ##@Testing Testo - every benchmark, one process per file (~20 min; -j3 ~11 min, rougher numbers)


test-bench-load: ##@Testing Testo - load benchmarks (10 tables x 100k rows = 1M, ~177 MiB in temp, ~6 min)
	$(call title,"Testo - load benchmarks")
	$(call bench_files,$(BENCH_LOAD))


test-bench-mapping: ##@Testing Testo - array vs DTO across filter/sort/relation modes (1 table x 100k, ~51 MiB, ~11 min)
	$(call title,"Testo - mapping benchmarks")
	$(call bench_files,$(BENCH_MAPPING))


test-bench-index: ##@Testing Testo - index paths planned to change: unique, FK, composite, size, count, stale stamp (14 tables, ~2.5 min)
	$(call title,"Testo - index benchmarks")
	$(call bench_files,$(BENCH_INDEX))


#### PHP-CS-Fixer #####################################################################
test-phpcsfixer: ##@Testing PHP-CS-Fixer - check code style (dry-run)
	$(call title,"Check Coding Standards with PHP-CS-Fixer")
	@$(VENDOR_BIN)/php-cs-fixer fix \
        --config="$(PATH_ROOT)/.php-cs-fixer.php" \
        --dry-run \
        --allow-unsupported-php-version=yes \
        -vvv \
        --format=checkstyle > "$(PATH_BUILD)/phpcsfixer.xml"


test-phpcsfixer-fix: ##@Testing PHP-CS-Fixer - auto-fix code style
	$(call title,"Fix Coding Standards with PHP-CS-Fixer")
	@$(VENDOR_BIN)/php-cs-fixer fix \
        --config="$(PATH_ROOT)/.php-cs-fixer.php" \
        --allow-unsupported-php-version=yes \
        -vvv


#### PHPStan - Static Analysis Tool ###################################################
test-phpstan: ##@Testing PHPStan - static analysis (src + tests)
	$(call title,"PHPStan - Static Analysis Tool")
	@$(VENDOR_BIN)/phpstan analyse \
        --configuration="$(PATH_ROOT)/phpstan.neon" \
        --memory-limit=$(PHP_MEMORY_LIMIT) \
        --error-format="checkstyle" \
        --no-ansi \
        > "$(PATH_BUILD)/phpstan-checkstyle.xml"
