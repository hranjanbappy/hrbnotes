---
title: SAFIR Thermal Analysis
tags: [research, fire, simulation]
---

# SAFIR Thermal Analysis

SAFIR is a finite-element program for the simulation of structures subjected to
fire. It models both the #thermal and #structural response of members.

## Workflow

1. Define the geometry and mesh.
2. Run the thermal analysis to obtain temperature fields.
3. Feed temperatures into the structural step.

> Always validate against a known [[Composite Beam]] benchmark before trusting
> a new model.

## Related notes

- [[Fire Design]] — code-based design rules.
- [[Composite Beam]] — worked example used for validation.

## Sample input table

| Step | Time (s) | Max Temp (°C) |
|------|----------|---------------|
| 1    | 0        | 20            |
| 2    | 1800     | 612           |
| 3    | 3600     | 845           |

```python
# Quick parser for SAFIR temperature output
def parse_temps(path):
    with open(path) as f:
        return [float(x) for x in f if x.strip()]
```

See also #research/fire for the wider topic.
