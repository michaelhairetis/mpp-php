IMAGE := mpp-php-dev
RUN := docker run --rm -v $(CURDIR):/app -w /app $(IMAGE)

.PHONY: build install test stan fmt shell clean

build:
	docker build -q -t $(IMAGE) .

install: build
	$(RUN) composer install --no-interaction

test: build
	$(RUN) vendor/bin/phpunit

stan: build
	$(RUN) vendor/bin/phpstan analyse --no-progress

fmt: build
	$(RUN) vendor/bin/php-cs-fixer fix

shell: build
	docker run --rm -it -v $(CURDIR):/app -w /app $(IMAGE) bash

# Remove the dev image once work is merged. Rebuild is one make away.
clean:
	-docker rmi $(IMAGE)
	rm -rf vendor .phpunit.cache
