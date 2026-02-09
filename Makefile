DOCKER_COMPOSE_FILE := .docker/docker-compose.yaml
DOCKER_COMPOSE = docker compose -f $(DOCKER_COMPOSE_FILE) -p app-google

.PHONY: help build up down status shell logs prod-build prod-up prod-down prod-status prod-shell prod-logs

# --- Development Commands ---

build:
	$(DOCKER_COMPOSE) build app-google

up:
	$(DOCKER_COMPOSE) up -d app-google

down:
	$(DOCKER_COMPOSE) down --remove-orphans

status:
	$(DOCKER_COMPOSE) ps

shell:
	$(DOCKER_COMPOSE) exec app-google bash

logs:
	$(DOCKER_COMPOSE) logs -f app-google

# --- Production Commands (Simulation) ---

prod-build:
	$(DOCKER_COMPOSE) build app-google-prod

prod-up:
	$(DOCKER_COMPOSE) up -d app-google-prod

prod-down:
	$(DOCKER_COMPOSE) down --remove-orphans

prod-status:
	$(DOCKER_COMPOSE) ps

prod-shell:
	$(DOCKER_COMPOSE) exec app-google-prod bash

prod-logs:
	$(DOCKER_COMPOSE) logs -f app-google-prod
