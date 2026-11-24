/**
 * BlockedMembersSection
 *
 * Lets a member review and unblock accounts they have blocked. Blocks are
 * symmetric for visibility but only the blocker can remove their own block.
 */

"use client";

import { SettingsSection } from "@/components/settings/SettingsSection";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import {
  getBlockedMembers,
  unblockMember,
  type BlockedMember,
} from "@/lib/api/safety";
import { ShieldBan } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

export function BlockedMembersSection() {
  const { confirm } = useConfirm();
  const [blocked, setBlocked] = useState<BlockedMember[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    try {
      setBlocked(await getBlockedMembers());
    } catch {
      setError("Unable to load blocked members.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleUnblock = async (member: BlockedMember) => {
    const name = `${member.first_name} ${member.last_name}`.trim() || "Blocked member";
    const confirmed = await confirm({
      title: `Unblock ${name}?`,
      description:
        "They will be able to appear in your search and discovery results again, but they are not notified.",
      confirmLabel: "Unblock",
    });
    if (!confirmed) return;

    setBusyId(member.id);
    setError(null);
    try {
      const response = await unblockMember(member.id);
      setMessage(response.message);
      await load();
    } catch {
      setError("Unable to unblock this member.");
    } finally {
      setBusyId(null);
    }
  };

  return (
    <SettingsSection
      title="Blocked members"
      description="Blocked members are hidden from your search, discovery and connection lists in both directions."
    >
      {message && <p className="mb-3 text-sm text-emerald-400">{message}</p>}
      {error && <p className="mb-3 text-sm text-red-400">{error}</p>}

      {isLoading ? (
        <p className="text-sm text-roicard-text-muted">Loading blocked members…</p>
      ) : blocked.length === 0 ? (
        <p className="rounded-xl border border-dashed border-roicard-border p-4 text-sm text-roicard-text-muted">
          You have not blocked anyone.
        </p>
      ) : (
        <div className="space-y-3">
          {blocked.map((member) => {
            const name =
              `${member.first_name} ${member.last_name}`.trim() || "Blocked member";
            return (
              <div
                key={member.id}
                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-3"
              >
                <div className="flex items-center gap-3">
                  <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-roicard-bg-muted text-roicard-text-muted">
                    <ShieldBan className="h-4 w-4" />
                  </span>
                  <div>
                    <p className="text-sm font-medium text-roicard-text">{name}</p>
                    <p className="text-xs text-roicard-text-muted">
                      {member.profile?.title || member.profile?.organisation || "Profile hidden"}
                    </p>
                  </div>
                </div>
                <Button
                  size="sm"
                  variant="secondary"
                  className="rounded-lg"
                  onClick={() => handleUnblock(member)}
                  isLoading={busyId === member.id}
                >
                  Unblock
                </Button>
              </div>
            );
          })}
        </div>
      )}
    </SettingsSection>
  );
}
