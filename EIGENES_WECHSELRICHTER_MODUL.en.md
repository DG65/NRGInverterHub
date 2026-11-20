# Connecting your own inverter module to the NRG-Stack

This guide is for developers who have their **own Symcon module for an inverter or hybrid battery system** and want the EMS
and the display modules of the NRG-Stack to see it. Your module stays a standalone module. It does not need InverterHub,
and InverterHub does not need your module.

The guide describes the **`inverter` contract, version 1.10**, as InverterHub offers it with `IHUB_GetFunctions`.

## The principle

1. Your module offers a function `<PREFIX>_GetFunctions($id)` (prefix from `module.json`). It returns **an array with the
   fields below**, one per inverter instance. It must be fast, free of side effects and must not query the device.
2. Values are read through **variable IDs** (`...ID` fields). `0` means "not available", not "value 0".
3. Control happens through `IPS_RequestAction()` on the instance with fixed idents, never through a custom set function.

## Fields

| Field | Meaning |
|---|---|
| `contractVersion` | `'1.10'`. Consumers check the major version. |
| `contractType` | always `'inverter'` (without it the function is read as `function`) |
| `manufacturer`, `instanceID`, `virtual` (always `false` for a real device), `measured` (`true` only for real measurements) | identification |
| `pvPowerID`, `acPowerID`, `batPowerID`, `gridPowerID`, `socID`, `houseLoadID` | variable IDs in W or %, `0` if missing. The signs are already the **canonical** ones (see SUITE.md), not the raw device values. |
| `controllable`, `controlAuthority` | controllable yes/no and who may write: `ems`, `external` or `none` |
| `failsafeSec` | Seconds after which the device or your module falls back to a safe state on its own if commands stop. **`0` or missing = no failsafe: the EMS then does not release writing.** |
| `failsafeMode` | `'deadman'`: the EMS renews the command within `failsafeSec`/2 |
| `remoteControl` | control points (see below), empty array without remote control |

More optional fields (MPPT, battery per pack, phase currents) are listed in `CLAUDE.md` of the InverterHub repository.

## Control: `remoteControl`

IDs of the variables written with `IPS_RequestAction()`: `modeID`, `powerID`, `gridChargeID`, `socMinID`, `socMaxID`,
`failsafeSecID`; reported back are `availableID`, `activeID`, `statusID`, `testedID`.

- **Order:** set power, SOC limits and grid charging first, **the mode last**. Only writing the mode sends.
- **Modes:** `0` off (the device runs on its own again), `1` hold battery, `2` battery ±W, `3` grid connection point ±W.
  Offer only what your device can do and list it in `modesID`.
- **Signs:** mode 2: **+ charge, − discharge**. Mode 3: **+ feed-in, − import**.
- **Failsafe (required for control):** the deadman lives in the device or your module, not in the EMS. `failsafeSec` must be
  the value you really set in the device (after rounding and limiting). The EMS rewrites cyclically. Writing the same value
  again may only extend the period, never restart anything.
- **`testedID`:** `false` if the connection was built from the manufacturer document only and is not yet confirmed on a
  device. The EMS controls such devices only with the user's explicit consent.

## What the EMS does

It finds third-party modules through the module list and the prefix, reads at once and **controls only after the user has
released that instance**. Own modules take precedence, duplicates are recognised through `deviceSerial`. Values outside the
contract limits are discarded.
