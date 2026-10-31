# Corevia 3.0.0 — release preparation

Framework and SDK metadata is 3.0.0. The V3 branch is prepared for GitHub CI; the stable tag/release is not published until mandatory acceptance passes. CVP/1 and CVP/2 protocol versions remain unchanged.

Implemented locally: deterministic composition, runtime profiles, scoped lifecycle, architecture gates, signed config/build artifacts and cross-runtime contracts; Bootstrap/Tailwind/React auth kits with MFA, account recovery/management and authorization; GitHub/OIDC provider clients and MongoDB/CAS auth; shared SQL jobs, fencing and encrypted mail; streaming AI/embeddings/retrieval services and Python model hosting; authenticated AI starter with JS/Dart streaming; deployment and release-evidence tooling.

See the completed [14-point/security/upgrade review](docs/v3/final-review.md), [upgrade notes](docs/v3/upgrade.md), and [deployment guide](deploy/README.md). Real database, TLS nginx/FPM and approved SMTP acceptance have private evidence. Live OAuth and Docker image validation are explicit maintainer deferrals, not test passes; [self-test instructions](docs/v3/optional-validation.md) are provided. Mandatory GitHub CI is still pending, and V3 remains unpublished. Reference indexes/aggregate stores are bounded; model weights/GPU scheduling, target load/failover and application rollout are operator responsibilities.
