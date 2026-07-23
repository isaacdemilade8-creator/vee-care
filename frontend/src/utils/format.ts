export function formatDate(value: string, options?: Intl.DateTimeFormatOptions) {
  return new Date(value).toLocaleDateString(undefined, options ?? { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDateLong(value: string) {
  return formatDate(value, { month: 'long', day: 'numeric', year: 'numeric' });
}

export function excerpt(value: string, length = 130) {
  return value.length > length ? `${value.slice(0, length).trim()}...` : value;
}

export function readingTime(text: string, wordsPerMinute = 180) {
  const words = text.trim().split(/\s+/).filter(Boolean).length;
  return `${Math.max(1, Math.ceil(words / wordsPerMinute))} min read`;
}
