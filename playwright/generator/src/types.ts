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
  title?: string;
  url?: string;
  pathname: string;
  effectivePathname?: string;
  phase?: 'guest' | 'auth';
  forms?: FormData[];
  components?: PageComponents;
}

export interface ResourceInfo {
  name: string;
  className: string;
  indexPath: string;
  createPath: string;
  fields: FormInput[];
  tableColumns: string[];
  hasCreatePage: boolean;
}

export interface AnalysisResult {
  hasAuth: boolean;
  hasProfile: boolean;
  hasDashboard: boolean;
  resources: ResourceInfo[];
}

export interface TestUser {
  name: string;
  email: string;
  password: string;
}

export interface GiteaOptions {
  enabled: boolean;
  serverUrl: string;
  appHost: string;
  playwrightImage: string;
  branch: string;
  npmCacheVolume: string;
}

export interface GeneratorOptions {
  baseUrl: string;
  testUser: TestUser;
  gitea: GiteaOptions;
}

export interface CliDefaults {
  datasetDir: string;
  outputDir: string;
  baseUrl: string;
}

export interface CliResult {
  datasetDir: string;
  outputDir: string;
  opts: GeneratorOptions;
  showHelp: boolean;
}
