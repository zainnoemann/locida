/**
 * Type definitions for the crawler module
 */

export interface FormInput {
  name: string | null;
  label: string;
  type: string;
  tag: string;
  placeholder: string | null;
  required: boolean;
}

export interface FormData {
  action: string | null;
  method: string;
  inputs: FormInput[];
}

export interface TableData {
  columns: string[];
  hasActions: boolean;
}

export interface ActionLink {
  text: string;
  tag: string;
  href: string | null;
}

export interface PageComponents {
  tables: TableData[];
  actions: ActionLink[];
}

export interface ExtractedPageData {
  title: string;
  url: string;
  pathname: string;
  effectivePathname: string;
  phase: 'guest' | 'auth';
  forms: FormData[];
  components: PageComponents;
}

export interface CrawlerConfig {
  startUrl: string;
  credentials: {
    email: string;
    password: string;
  };
  headless: boolean;
  guestSeedPaths: string[];
  protectedSeedPaths: string[];
  guestExcludePatterns: string[];
  authExcludePatterns: string[];
}

export interface Logger {
  info(message: string): void;
  warning(message: string): void;
}
