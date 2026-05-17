IMAGE := transactional-outbox

UID = $(shell id -u)
GID = $(shell id -g)
export UID
export GID

GREEN = \033[0;32m
YELLOW = \033[0;33m
RED = \033[0;31m
NC = \033[0m

build:
	docker build \
	  --build-arg UID=$(UID) \
	  --build-arg GID=$(GID) \
	  -t $(IMAGE) .

install:
	docker run --rm -it \
	  -v $(PWD):/app \
	  $(IMAGE) composer install

update:
	docker run --rm -it \
	  -v $(PWD):/app \
	  $(IMAGE) composer update

KNOWN_GROUPS=Unit Feature
test-groups:
	@echo "$(GREEN)Доступные группы тестов:$(NC)"
	@for group in $(KNOWN_GROUPS); do echo "  - $$group"; done

test:
	@echo "$(GREEN)Запуск всех тестов...$(NC)"
	docker run --rm -it -v $(PWD):/app $(IMAGE) ./vendor/bin/pest

test-%:
	@if echo "$(KNOWN_GROUPS)" | grep -wq "$*"; then \
		echo "$(GREEN)Запуск тестов группы '$*'...$(NC)"; \
		docker run --rm -it -v $(PWD):/app $(IMAGE) ./vendor/bin/pest --group=$*; \
	else \
		echo "$(RED)Неизвестная группа '$*'.$(NC)"; \
		$(MAKE) test-groups; \
		exit 1; \
	fi

shell:
	docker run --rm -it -v $(PWD):/app $(IMAGE) bash

pint:
	docker run --rm -it -v $(PWD):/app $(IMAGE) ./vendor/bin/pint

analyse:
	docker run --rm -it -v $(PWD):/app $(IMAGE) ./vendor/bin/phpstan analyse --memory-limit=2G
