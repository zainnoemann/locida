// ─── Code Generation Helper ───────────────────────────────────────────────────
// Replaces the verbose lines.push() pattern with a simple tagged-template builder.

export class CodeBuilder {
  private lines: string[] = [];

  /** Append one or more lines (blank string = empty line) */
  line(...text: string[]): this {
    this.lines.push(...text);
    return this;
  }

  /** Append a blank line */
  blank(): this {
    this.lines.push('');
    return this;
  }

  /** Append lines from another builder */
  append(other: CodeBuilder): this {
    this.lines.push(...other.toLines());
    return this;
  }

  /** Append raw multiline string (splits on \n) */
  raw(text: string): this {
    this.lines.push(...text.split('\n'));
    return this;
  }

  toLines(): string[] {
    return this.lines;
  }

  toString(): string {
    return this.lines.join('\n');
  }
}

export function code(): CodeBuilder {
  return new CodeBuilder();
}
