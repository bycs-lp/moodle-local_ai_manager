# QDrant Vector DB plugin

This is a local_ai_manager Vector DB plugin that uses qDrant vdb as the backend.

## Installation

### Docker (Moodle-docker)

Create a local.yml file

```
qdrant:
    image: qdrant:latest
    ports:
      - 6333:6333
      - 6334:6334
    expose:
      - 6333
      - 6334
      - 6335
    configs:
      - source: qdrant_config
        target: /qdrant/config/production.yaml
    volumes:
      - ./qdrant_data:/qdrant/storage

configs:
  qdrant_config:
    content: |
      log_level: INFO
```

using mython helper:
`./mython/helper start-moodle --config-file ../<moodlemain>/qdrant.docker.yml --db mariadb <moodlemain>`

Note: config-file path is relative to the moodle-docker location.