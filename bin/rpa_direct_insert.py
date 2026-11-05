#!/usr/bin/env python3
"""Insert one strictly shaped CIDB Bot_Input directly into RPA Hub."""

from __future__ import annotations

import argparse
import json
import os
import random
import sys
import time
from pathlib import Path
from typing import Any

import pymysql


BOT_INPUT_KEYS = (
    "sCRMID",
    "sEmail",
    "sCustomerName",
    "sIdentificationNumber",
    "sContactNumber",
    "sLanguage",
    "sChannel",
    "sRequest",
    "sCustomerType",
)


def read_env_file(path: Path) -> None:
    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        os.environ.setdefault(key.strip(), value)


def connect() -> pymysql.connections.Connection:
    missing = [key for key in ("MYSQL_HOST", "MYSQL_USER", "MYSQL_PASSWORD", "MYSQL_DB") if not os.getenv(key)]
    if missing:
        raise RuntimeError("RPA Hub environment is missing required MySQL settings: " + ", ".join(missing))
    return pymysql.connect(
        host=os.environ["MYSQL_HOST"],
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.environ["MYSQL_USER"],
        password=os.environ["MYSQL_PASSWORD"],
        database=os.environ["MYSQL_DB"],
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
        connect_timeout=5,
        read_timeout=10,
        write_timeout=10,
    )


def parse_payload(raw: str) -> dict[str, str]:
    payload = json.loads(raw)
    if not isinstance(payload, dict) or tuple(payload.keys()) != BOT_INPUT_KEYS:
        raise ValueError("Bot_Input must contain exactly the approved nine keys, in the approved order.")
    if any(not isinstance(value, str) or not value.strip() for value in payload.values()):
        raise ValueError("Every Bot_Input value must be a non-empty string.")
    if payload["sCustomerType"].strip().casefold() != "individual":
        raise ValueError("Direct CIDB cancellation insertion is restricted to CLOG-confirmed Individual cases.")
    if payload["sChannel"] != "Email":
        raise ValueError("Only Email channel payloads are supported.")
    if payload["sRequest"] not in {"Email Cancel", "Pending Customer Attachment"}:
        raise ValueError("sRequest must be Email Cancel or Pending Customer Attachment.")
    if payload["sRequest"] == "Email Cancel":
        raise ValueError(
            "Email Cancel is blocked in this standalone injector because it cannot verify portal documents; "
            "use the OCR-verified PHP workflow."
        )
    if not payload["sCRMID"].startswith("CRM-"):
        raise ValueError("sCRMID must be a CLOG CRM ID.")
    return payload


def schedule_id() -> str:
    return f"{random.randint(0, 100)}-{int(time.time())}-{random.randint(0, 100)}"


def insert(payload: dict[str, str], env_file: Path, actor: str) -> str:
    read_env_file(env_file)
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT hardcoded_json FROM ticket_scenarios "
                "WHERE company=%s AND scenario_key=%s AND is_active=1 LIMIT 1",
                ("CIDB", "cidb_masterbot"),
            )
            scenario_row = cur.fetchone()
            if not scenario_row:
                raise RuntimeError("Active CIDB masterbot scenario was not found.")
            hardcoded = json.loads(scenario_row["hardcoded_json"])
            play_id = int(hardcoded["PlayID"])
            cur.execute(
                "SELECT ScheduleName, GroupID, CompanyID, BotID FROM bot_playlist WHERE ID=%s LIMIT 1",
                (play_id,),
            )
            playlist = cur.fetchone()
            if not playlist or int(playlist["BotID"]) != 118:
                raise RuntimeError("Live playlist is missing or does not point to CIDB BotID 118.")
            cur.execute(
                "SELECT RunnerName, RunnerID, ControlRoomIP FROM rpa_runner "
                "WHERE RunnerName=%s LIMIT 1",
                ("BR20",),
            )
            runner = cur.fetchone()
            if not runner or not runner["RunnerID"]:
                raise RuntimeError("Live BR20 runner configuration is missing its RunnerID.")

            cur.execute(
                "SELECT id, ScheduleID FROM bot_scheduler "
                "WHERE CASE WHEN JSON_VALID(Bot_Input) "
                "THEN JSON_UNQUOTE(JSON_EXTRACT(Bot_Input, '$.sCRMID')) ELSE NULL END=%s "
                "ORDER BY id DESC LIMIT 1",
                (payload["sCRMID"],),
            )
            duplicate = cur.fetchone()
            if duplicate:
                raise RuntimeError(
                    f"A scheduler row already exists for {payload['sCRMID']} (schedule {duplicate['ScheduleID']}); refusing duplicate."
                )

            sid = schedule_id()
            values: dict[str, Any] = {
                "ScheduleType": hardcoded.get("ScheduleType", "Production"),
                "GlobalStatus": hardcoded.get("GlobalStatus", "RUN"),
                "ScheduleID": sid,
                "ScheduleName": playlist["ScheduleName"],
                "PlayOrder": hardcoded.get("PlayOrder", 1),
                "PlayID": play_id,
                "GroupID": playlist["GroupID"],
                "CompanyID": playlist["CompanyID"],
                "BotID": playlist["BotID"],
                "ControlRoomIP": runner["ControlRoomIP"],
                "RunnerName": runner["RunnerName"],
                "RunnerID": runner["RunnerID"],
                "Bot_Input": json.dumps(payload, ensure_ascii=False, separators=(",", ":")),
                "CreatedBy": actor,
            }
            columns = tuple(values)
            cur.execute(
                f"INSERT INTO bot_scheduler ({', '.join(columns)}, BotType, DigitalWorkerType, "
                "ScheduleDateTime, ScheduleDateTimeE, CreatedDateTime, AssignedDateTime, "
                "CompletionStatus, RecordingRequired, Intervention) "
                f"VALUES ({', '.join(['%s'] * len(columns))}, 'Automation Anywhere', "
                "'Dedicated - On Premise', NOW(), NOW(), NOW(), NOW(), 'PENDING', 'No', 'No')",
                tuple(values[column] for column in columns),
            )
            cur.execute(
                "INSERT INTO hub_audit_log "
                "(actor_username, action, resource_type, resource_id, success, client_ip, detail_json) "
                "VALUES (%s, %s, %s, %s, 1, NULL, %s)",
                (
                    actor,
                    "external_ticket_insert.direct_db",
                    "bot_scheduler",
                    sid,
                    json.dumps(
                        {
                            "company": "CIDB",
                            "scenario": "cidb_masterbot",
                            "channel": payload["sChannel"],
                            "runner_name": runner["RunnerName"],
                            "runner_id": runner["RunnerID"],
                            "bot_input_fields": list(payload),
                        },
                        ensure_ascii=False,
                    ),
                ),
            )
        conn.commit()
        return sid
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env-file", type=Path, required=True, help="RPA Hub .env file (never printed).")
    parser.add_argument("--actor", default="cidb_direct_insert", help="Audit username.")
    parser.add_argument("--dry-run", action="store_true", help="Validate the exact payload without connecting or inserting.")
    args = parser.parse_args()
    try:
        payload = parse_payload(sys.stdin.read())
        if args.dry_run:
            print(json.dumps({"status": "validated", "crm_id": payload["sCRMID"], "bot_input_fields": list(payload)}))
            return 0
        sid = insert(payload, args.env_file, args.actor)
        print(json.dumps({"status": "inserted", "crm_id": payload["sCRMID"], "schedule_id": sid}))
        return 0
    except Exception as exc:
        print(f"Direct insert failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
