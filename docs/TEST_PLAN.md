# Test plan

Phase 0 must validate the public health JSON contract, rejection of unauthenticated API access, Sanctum bearer-token authentication, React loading/success/failure/retry behavior, the Flutter starter and Python environment. The full test command must also compile the web app.

Before marking Phase 0 complete, run setup against a clean checkout with ignored dependencies and environment files absent, then launch dev and check the API through Vite's proxy. Record actual results in PROGRESS.md.

Later phases add simulation-label, consent, retention, GPS validation, route matching and measured ETA-accuracy tests alongside each feature.
