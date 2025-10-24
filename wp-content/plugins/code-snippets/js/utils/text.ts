export const toCamelCase = (text: string): string =>
	text.replace(/-(?<letter>[a-z])/g, (_, letter: string) => letter.toUpperCase())

export const trimLeadingChar = (text: string, character: string): string =>
	character === text.charAt(0) ? text.slice(1) : text

export const trimTrailingChar = (text: string, character: string): string =>
	character === text.charAt(text.length - 1) ? text.slice(0, -1) : text

export const truncateWords = (text: string, wordCount: number): string => {
	const words = text.trim().split(/\s+/)

	return words.length > wordCount
		? `${words.slice(0, wordCount).join(' ')}…`
		: text
}

export const stripTags = (text: string): string =>
	text
		.replace(/<!--[\s\S]*?-->|<\?(?:php)?[\s\S]*?\?>/gi, '')
		.replace(/<\/?[a-z][a-z0-9]*\b[^>]*>/gi, '')
