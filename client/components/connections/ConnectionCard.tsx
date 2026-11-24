/**
 * ConnectionCard
 *
 * Displays an established connection with profile info and date connected,
 * plus safety actions (remove, block, report).
 */

import { ConnectionAvatar } from "@/components/connections/ConnectionAvatar";
import { Button } from "@/components/ui/Button";
import {
  formatConnectionDate,
  getPersonFullName,
} from "@/lib/connections/helpers";
import type { Connection, ConnectionPerson } from "@/lib/connections/types";
import { Flag, ShieldBan, UserMinus } from "lucide-react";
import Link from "next/link";

type ConnectionCardProps = {
  connection: Connection;
  onViewGuest?: (person: ConnectionPerson) => void;
  onRemove?: (connection: Connection) => void;
  onBlock?: (connection: Connection) => void;
  onReport?: (connection: Connection) => void;
  isProcessing?: boolean;
};

export function ConnectionCard({
  connection,
  onViewGuest,
  onRemove,
  onBlock,
  onReport,
  isProcessing = false,
}: ConnectionCardProps) {
  const { person } = connection;
  const fullName = getPersonFullName(person);

  const name = person.username ? (
    <Link
      href={`/${person.username}`}
      className="truncate font-semibold text-roicard-text underline-offset-4 hover:text-roicard-accent hover:underline"
    >
      {fullName}
    </Link>
  ) : onViewGuest ? (
    <button
      type="button"
      onClick={() => onViewGuest(person)}
      className="truncate font-semibold text-roicard-text underline-offset-4 hover:text-roicard-accent hover:underline"
      title="View guest profile"
    >
      {fullName}
    </button>
  ) : (
    <span className="truncate font-semibold text-roicard-text">{fullName}</span>
  );

  return (
    <article className="glass-card rounded-2xl p-4 transition-colors hover:border-roicard-accent/25 sm:p-5">
      <div className="flex items-center gap-4">
        <ConnectionAvatar person={person} size="lg" />

        <div className="min-w-0 flex-1">
          <h3 className="truncate">{name}</h3>
          <p className="mt-0.5 truncate text-sm text-roicard-text-muted">
            {person.professionalTitle}
          </p>
          {person.organization && (
            <p className="truncate text-sm text-roicard-text-muted/80">
              {person.organization}
            </p>
          )}
          <p className="mt-2 text-xs text-roicard-accent">
            Connected {formatConnectionDate(connection.connectedAt)}
          </p>
        </div>
      </div>

      {(onRemove || onBlock || onReport) && (
        <div className="mt-3 flex flex-wrap gap-2 border-t border-roicard-border pt-3">
          {onRemove && connection.requestType === "member" && (
            <Button
              size="sm"
              variant="secondary"
              className="rounded-lg"
              onClick={() => onRemove(connection)}
              disabled={isProcessing}
            >
              <UserMinus className="h-3.5 w-3.5" />
              Remove connection
            </Button>
          )}
          {onBlock && person.userId && (
            <Button
              size="sm"
              variant="ghost"
              className="rounded-lg"
              onClick={() => onBlock(connection)}
              disabled={isProcessing}
            >
              <ShieldBan className="h-3.5 w-3.5" />
              Block
            </Button>
          )}
          {onReport && person.userId && (
            <Button
              size="sm"
              variant="ghost"
              className="rounded-lg"
              onClick={() => onReport(connection)}
              disabled={isProcessing}
            >
              <Flag className="h-3.5 w-3.5" />
              Report
            </Button>
          )}
        </div>
      )}
    </article>
  );
}
