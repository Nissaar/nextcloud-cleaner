#!/usr/bin/env python3
"""
SPDX-FileCopyrightText: 2026 Nissaar
SPDX-License-Identifier: AGPL-3.0-or-later

Writes the test library used by the acceptance run.

Every file is the same tiny valid JPEG; only the names differ, because the names are
the point. Each one exercises a different link in the date chain, and the last two are
traps: they look like dates and must not be read as one.
"""

import base64
import os
import sys

# A valid 1x1 JPEG. Small enough to commit the generator rather than the files.
JPEG = base64.b64decode(
    "/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a"
    "HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA"
    "AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=="
)

NAMES = [
    "IMG_20240712_140325.jpg",         # Android camera      -> 2024-07, filename
    "PXL_20230815_143022123.jpg",      # Pixel, milliseconds -> 2023-08, filename
    "Screenshot_20240103-091500.jpg",  # screenshot          -> 2024-01, filename
    "2022-05-19-08-30-00.jpg",         # dashed with time    -> 2022-05, filename
    "holiday photo.jpg",               # nothing parseable   -> mtime
    "84021599.jpg",                    # a serial number, not a date
    "20241312.jpg",                    # month 13; PHP would roll this into January
]


def main(target: str) -> None:
    os.makedirs(target, exist_ok=True)
    for name in NAMES:
        with open(os.path.join(target, name), "wb") as handle:
            handle.write(JPEG)
    print(f"{len(NAMES)} fixtures written to {target}")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "photos")
