# Entity relationship model

Phase 0 installs the framework user, session, cache, job and Sanctum token tables. Phase 1 adds the transport data tables below; no vehicles or GPS pings exist yet.

```mermaid
erDiagram
    routes ||--o{ route_stops : visits
    stops ||--o{ route_stops : appears_in
    routes ||--o{ route_segments : partitions
    routes {
        bigint id PK
        string code UK
        string name
        string direction
        string mode
        json geometry_geojson
        double length_m
        string verification_status
        json source_metadata
    }
    stops {
        bigint id PK
        string code UK
        string name
        double lat
        double lng
        string source
        string verification_status
    }
    route_stops {
        bigint id PK
        bigint route_id FK
        bigint stop_id FK
        int sequence
        double distance_along_route_m
        double offset_m
    }
    route_segments {
        bigint id PK
        bigint route_id FK
        int sequence
        double start_m
        double end_m
        double length_m
        json polyline
    }
```

`routes.code` identifies a direction. Both link tables have unique `(route_id, sequence)` constraints. Stop IDs may appear at multiple sequences for repeated visits. Route deletion cascades to its links/segments; stop deletion is restricted while referenced. Coordinates are WGS84, GeoJSON `[longitude, latitude]`; API stop fields retain named `lat`/`lng`. Distances are spherical meters using mean Earth radius 6,371,008.8 m.
