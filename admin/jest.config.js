module.exports = {
	testEnvironment: 'jsdom',
	transform: {
		'^.+\\.jsx?$': 'babel-jest',
	},
	transformIgnorePatterns: [
		'/node_modules/(?!(clsx|@wordpress)/)',
	],
	moduleNameMapper: {
		'\\.(scss|css)$': '<rootDir>/src/__mocks__/styleMock.js',
	},
};
