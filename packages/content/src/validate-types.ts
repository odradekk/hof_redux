import type { LoadedContent } from "./types.js";

export interface Diagnostic {
  code: string;
  severity: "error" | "warning";
  file: string;
  definitionId?: string;
  fieldPath?: string;
  message: string;
  source?: string;
  decisionRef?: string;
}

export interface ValidationResult {
  diagnostics: Diagnostic[];
  errors: Diagnostic[];
  content?: LoadedContent;
}
