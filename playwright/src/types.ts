// ─── Domain Types ────────────────────────────────────────────────────────────

export interface FormField {
  id: string;
  name: string;
  label: string;
  type: 'text' | 'password' | 'email' | 'checkbox' | 'textarea' | 'select' | string;
  required: boolean;      // NOT NULL in migration (server-side required)
  htmlRequired: boolean;  // has HTML `required` attribute
}

export interface ParsedView {
  path: string;
  resourceName: string;
  viewType: 'index' | 'create' | 'edit' | 'show' | 'auth' | 'profile' | 'dashboard' | 'other';
  fields: FormField[];
  tableColumns: string[];
  hasTable: boolean;
  hasDeleteButton: boolean;
  hasEditLink: boolean;
  hasCreateLink: boolean;
  buttonLabels: string[];
  pageTitle: string;
}

export interface RouteInfo {
  name: string;
  method: string;
  uri: string;
  action?: string;
  middleware: string[];
  isResource: boolean;
}

export interface RelationInfo {
  field: string;
  relatedResource: string;
  label: string;
}

export interface ResourceGroup {
  name: string;
  singular: string;
  className: string;
  routes: RouteInfo[];
  views: ParsedView[];
  fields: FormField[];
  tableColumns: string[];
  requiresAuth: boolean;
  hasIndex: boolean;
  hasCreate: boolean;
  hasEdit: boolean;
  hasDelete: boolean;
  hasShow: boolean;
  relations: RelationInfo[];
}

export interface MigrationField {
  name: string;
  type: string;
  nullable: boolean;
  foreignKey?: string;
}

export interface ParsedMigration {
  tableName: string;
  fields: MigrationField[];
}

export interface ProjectAnalysis {
  projectPath: string;
  resources: ResourceGroup[];
  hasAuth: boolean;
  hasProfile: boolean;
  authViews: ParsedView[];
  baseUrl: string;
  testUser: { email: string; password: string; name: string };
}

export interface GeneratorOptions {
  outputDir: string;
  baseUrl: string;
  testUser: { email: string; password: string; name: string };
  gitea: {
    enabled: boolean;
    serverUrl: string;
    appHost: string;
    playwrightImage: string;
    branch: string;
    npmCacheVolume: string;
    reportVolume: string;
  };
}
