"use client";

import { AuthDivider } from "@/components/auth/AuthDivider";
import { SocialAuthRow } from "@/components/auth/SocialAuthButton";
import { InputField } from "@/components/auth/InputField";
import { Button } from "@/components/ui/Button";
import { PasswordStrengthChecklist } from "@/components/ui/PasswordStrengthChecklist";
import { useAuth } from "@/contexts/AuthContext";
import { arePasswordRulesMet } from "@/lib/validation/password";
import { useRouter, useSearchParams } from "next/navigation";
import { FormEvent, useState } from "react";

export function RegisterForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { register } = useAuth();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [campaignCode, setCampaignCode] = useState(
    () =>
      searchParams.get("campaign_code") ??
      searchParams.get("campaign") ??
      ""
  );
  const [referralCode, setReferralCode] = useState(
    () => searchParams.get("ref") ?? ""
  );
  const [error, setError] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [privacyAccepted, setPrivacyAccepted] = useState(false);

  const allPassed = arePasswordRulesMet(password, confirmPassword);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!allPassed) return;

    if (!privacyAccepted) {
      setError("Confirm that you agree to profile processing to create an account.");
      return;
    }

    setIsLoading(true);
    setError("");
    try {
      const parts = name.trim().split(" ");
      const firstName = parts[0] || "";
      const lastName = parts.slice(1).join(" ") || firstName;
      await register({
        first_name: firstName,
        last_name: lastName,
        email,
        password,
        password_confirmation: confirmPassword,
        campaign_code: campaignCode.trim() || undefined,
        referral_code: referralCode.trim() || undefined,
        privacy_consent: true,
      });
      const next = searchParams.get("next");
      const query = new URLSearchParams({ email });
      if (next) query.set("next", next);
      router.push(`/auth/verify-email?${query.toString()}`);
    } catch (err: unknown) {
      const msg =
        err && typeof err === "object" && "response" in err
          ? (err as { response: { data: { message: string } } }).response?.data?.message
          : "Registration failed";
      setError(msg || "Registration failed");
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="space-y-6">
      <SocialAuthRow mode="signup" />

      <AuthDivider />

      <form className="space-y-5" onSubmit={handleSubmit} noValidate>
        <InputField
          label="Full Name"
          name="name"
          placeholder="Alex Morgan"
          autoComplete="name"
          value={name}
          onChange={(event) => setName(event.target.value)}
        />

        <InputField
          label="Email"
          type="email"
          name="email"
          placeholder="you@example.com"
          autoComplete="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
        />

        <InputField
          label="Password"
          type="password"
          name="password"
          placeholder="••••••••"
          autoComplete="new-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />

        {/* Password strength checklist */}
        <PasswordStrengthChecklist
          password={password}
          confirmPassword={confirmPassword}
          className="-mt-2"
        />

        <InputField
          label="Confirm Password"
          type="password"
          name="password_confirmation"
          placeholder="••••••••"
          autoComplete="new-password"
          value={confirmPassword}
          onChange={(event) => setConfirmPassword(event.target.value)}
        />

        <InputField
          label="Campaign code (optional)"
          name="campaign_code"
          placeholder="e.g. NLF2026"
          autoComplete="off"
          value={campaignCode}
          onChange={(event) => setCampaignCode(event.target.value.toUpperCase())}
        />

        <InputField
          label="Referral code (optional)"
          name="referral_code"
          placeholder="e.g. RCAB12CD34"
          autoComplete="off"
          hint="From the member who invited you, or pre-filled by their referral link."
          value={referralCode}
          onChange={(event) => setReferralCode(event.target.value.toUpperCase())}
        />

        <label className="flex items-start gap-2 text-xs text-roicard-text-muted">
          <input
            type="checkbox"
            checked={privacyAccepted}
            onChange={(event) => setPrivacyAccepted(event.target.checked)}
            className="mt-0.5 h-4 w-4 rounded border-roicard-border"
          />
          <span>
            I agree that ROICARD may process the profile information I provide
            to operate my account, profile, search and connection features.
          </span>
        </label>

        {error && (
          <p className="text-sm text-red-500 text-center">{error}</p>
        )}

        <Button type="submit" fullWidth isLoading={isLoading} className="h-12 rounded-xl">
          Create Account
        </Button>
      </form>
    </div>
  );
}
